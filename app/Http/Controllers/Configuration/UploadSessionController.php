<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ApplicationSettingRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\InstallationMaintenanceAccessService;
use App\Services\LicenseService;
use App\Services\NetworkService;
use App\Services\QrUploadService;
use App\Services\TunnelService;
use App\Services\UploadDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class UploadSessionController extends Controller
{
    private const ACTIVE_UPLOAD_COOKIE = 'medismart_active_upload';

    private const ACTIVE_UPLOAD_COOKIE_PATH = '/app/configuration/connectivity-backup';

    public function __construct(
        private readonly InstallationMaintenanceAccessService $installationMaintenance,
    ) {}

    public function store(
        Request $request,
        QrUploadService $sessions,
        TunnelService $tunnel,
        LicenseService $licenses,
        ApplicationSettingService $settings,
        NetworkService $network,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        $data = $request->validate([
            'mode' => ['required', Rule::in(['local', 'remote', 'relay'])],
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('deleted_at')],
        ]);

        $mode = $data['mode'];

        if ($mode === 'local'
            && (! $settings->get(ApplicationSettingRegistry::CONNECTIVITY_LAN_ENABLED)
                || ! $network->lanListenerActive())) {
            return back()->withErrors([
                'mode' => __('Enable the verified desktop LAN listener before creating a local QR code.'),
            ]);
        }

        if ($mode === 'remote') {
            $status = $tunnel->status();

            if (! $licenses->featureEnabled('remote_upload')
                || $status['runtime_state'] !== 'active'
                || ! $status['configured']) {
                return back()->withErrors([
                    'mode' => __('Remote upload is not licensed, configured, and verified as active.'),
                ]);
            }
        }

        if ($mode === 'relay') {
            if (! $licenses->featureEnabled('remote_relay')) {
                return back()->withErrors([
                    'mode' => __('The active license does not allow secure relay uploads.'),
                ]);
            }

            return back()->withErrors([
                'mode' => __('The secure relay is not configured on this installation.'),
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        /** @var Patient $patient */
        $patient = Patient::query()->findOrFail($data['patient_id']);
        $created = $sessions->create($mode, $user, $patient);

        if (! is_string($created['url']) || $created['url'] === '') {
            $sessions->revoke($created['session'], $user);

            return back()->withErrors([
                'mode' => __('No reachable upload address is available for this mode.'),
            ]);
        }

        $activeUpload = json_encode([
            'id' => $created['session']->getKey(),
            'mode' => $created['session']->mode,
            'url' => $created['url'],
            'issued_at' => now()->getTimestamp(),
            'reachability' => [
                'state' => 'not_tested',
                'checked_at' => null,
                'message' => null,
            ],
        ], JSON_THROW_ON_ERROR);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('A short-lived upload link was created.'),
        ]);

        return back()->withCookie(cookie(
            self::ACTIVE_UPLOAD_COOKIE,
            $activeUpload,
            2,
            self::ACTIVE_UPLOAD_COOKIE_PATH,
            null,
            $request->isSecure(),
            true,
            false,
            'strict',
        ));
    }

    public function test(
        Request $request,
        string $uploadSession,
        QrUploadService $sessions,
    ): RedirectResponse {
        $this->installationMaintenance->authorize($request->user());

        $uploadSession = UploadSession::query()->findOrFail($uploadSession);
        $payload = $this->activeUploadPayload($request, $sessions);

        if ($payload === null || $payload['id'] !== (string) $uploadSession->getKey()) {
            return back()->withErrors([
                'reachability' => 'Le lien QR actif de cet écran n’est plus vérifiable. Générez un nouveau lien.',
            ]);
        }

        $url = $this->reachabilityProbeUrl($payload['url']);
        $checkedAt = now()->toIso8601String();

        try {
            $response = Http::connectTimeout(2)
                ->timeout(4)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
            $reachable = $response->ok()
                && $response->header('X-MediSmart-Upload-Portal') === 'ready';
        } catch (Throwable) {
            $reachable = false;
        }

        $payload['reachability'] = [
            'state' => $reachable ? 'verified' : 'failed',
            'checked_at' => $checkedAt,
            'message' => $reachable
                ? 'Le portail de téléversement Drclick répond à cette adresse.'
                : 'Cette adresse ne répond pas comme un portail de téléversement Drclick.',
        ];

        $activeUpload = json_encode($payload, JSON_THROW_ON_ERROR);

        Inertia::flash('toast', [
            'type' => $reachable ? 'success' : 'error',
            'message' => $payload['reachability']['message'],
        ]);

        $response = back()->withCookie(cookie(
            self::ACTIVE_UPLOAD_COOKIE,
            $activeUpload,
            2,
            self::ACTIVE_UPLOAD_COOKIE_PATH,
            null,
            $request->isSecure(),
            true,
            false,
            'strict',
        ));

        return $reachable
            ? $response
            : $response->withErrors(['reachability' => $payload['reachability']['message']]);
    }

    public function destroy(
        Request $request,
        UploadSession $uploadSession,
        QrUploadService $sessions,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $sessions->revoke($uploadSession, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The upload link was revoked.'),
        ]);

        return back();
    }

    public function accept(
        Request $request,
        UploadedDocument $uploadedDocument,
        UploadDocumentService $documents,
    ): RedirectResponse {
        $data = $request->validate([
            'patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->whereNull('deleted_at')],
        ]);
        /** @var Patient|null $patient */
        $patient = isset($data['patient_id'])
            ? Patient::query()->findOrFail($data['patient_id'])
            : null;

        /** @var User $user */
        $user = $request->user();
        $documents->accept($uploadedDocument, $user, $patient);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The file was added to the patient record.'),
        ]);

        return back();
    }

    public function preview(
        Request $request,
        UploadedDocument $uploadedDocument,
        UploadDocumentService $documents,
    ): StreamedResponse {
        $path = $documents->assertReviewable($uploadedDocument);
        AuditLog::record(
            'upload.previewed',
            $uploadedDocument,
            [],
            $request->user()?->getKey(),
        );

        return Storage::disk('local')->response(
            $path,
            $uploadedDocument->original_name,
            [
                'Cache-Control' => 'no-store, private, max-age=0',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    'inline',
                    $uploadedDocument->original_name,
                ),
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function reject(
        Request $request,
        UploadedDocument $uploadedDocument,
        UploadDocumentService $documents,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $documents->reject($uploadedDocument, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The file was rejected and removed.'),
        ]);

        return back();
    }

    /** @return array{id: string, mode: string, url: string, issued_at: int, reachability?: array<string, string|null>}|null */
    private function activeUploadPayload(Request $request, QrUploadService $uploads): ?array
    {
        $encoded = $request->cookie(self::ACTIVE_UPLOAD_COOKIE);

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $payload = json_decode($encoded, true);

        if (! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || ! is_string($payload['mode'] ?? null)
            || ! is_string($payload['url'] ?? null)
            || ! is_int($payload['issued_at'] ?? null)
            || abs(now()->getTimestamp() - $payload['issued_at']) > 180) {
            return null;
        }

        $path = parse_url($payload['url'], PHP_URL_PATH);
        $fragment = parse_url($payload['url'], PHP_URL_FRAGMENT);

        if (! is_string($path) || ! is_string($fragment)) {
            return null;
        }

        parse_str($fragment, $fragmentData);
        $selector = basename($path);
        $verifier = $fragmentData['v'] ?? null;

        if (! is_string($verifier)) {
            return null;
        }

        $session = $uploads->findByToken($selector, $verifier);

        if (! $session instanceof UploadSession
            || $session->getKey() !== $payload['id']
            || ! $session->isUsable()) {
            return null;
        }

        return $payload;
    }

    private function reachabilityProbeUrl(string $url): string
    {
        $position = strpos($url, '#');

        return $position === false ? $url : substr($url, 0, $position);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CabinetSetting;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Services\DocumentBrandingService;
use App\Services\QrUploadService;
use App\Services\UploadAudienceService;
use App\Services\UploadDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

final class PublicUploadController extends Controller
{
    public function show(
        string $selector,
        DocumentBrandingService $branding,
    ): Response {
        config(['livewire.inject_assets' => false]);
        SupportAutoInjectedAssets::$forceAssetInjection = false;
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        $identity = $branding->identity(cabinet: CabinetSetting::current());
        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $contentSecurityPolicy = implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "font-src 'none'",
            "img-src 'none'",
            "manifest-src 'none'",
            "media-src 'none'",
            "object-src 'none'",
            "script-src 'nonce-{$nonce}'",
            "script-src-attr 'none'",
            "style-src 'nonce-{$nonce}'",
            "style-src-attr 'none'",
            "worker-src 'none'",
        ]);

        return response()->view('uploads.public', [
            'nonce' => $nonce,
            'clinic' => [
                'name' => $identity['clinic_name'],
                'phone' => $identity['phone'],
                'city' => $identity['city'],
            ],
            'configuration' => [
                'selector' => $selector,
                'endpoints' => [
                    'authorize' => route('upload.session', ['selector' => $selector], false),
                    'files' => route('upload.files.store', ['selector' => $selector], false),
                    'complete' => route('upload.complete', ['selector' => $selector], false),
                ],
            ],
        ])->header('Content-Security-Policy', $contentSecurityPolicy);
    }

    public function session(
        Request $request,
        string $selector,
        QrUploadService $sessions,
        UploadAudienceService $audience,
    ): JsonResponse {
        $verifier = $this->verifier($request);
        $session = $sessions->resolve($selector, $verifier);

        abort_unless($session instanceof UploadSession, 404);
        $audience->assertAllowed($request, $session);

        return response()->json($this->payload($session));
    }

    public function store(
        Request $request,
        string $selector,
        QrUploadService $sessions,
        UploadDocumentService $documents,
        UploadAudienceService $audience,
    ): JsonResponse|RedirectResponse {
        $verifier = $this->verifier($request);
        $session = $this->resolve($sessions, $selector, $verifier);
        $audience->assertAllowed($request, $session);
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.$session->maximum_files],
            'files.*' => ['required', 'file'],
        ]);

        /** @var list<UploadedFile> $files */
        $files = array_values($data['files']);
        $documents->receive($session, $files, $request->ip(), $request->userAgent());

        $message = __('Files received. They are waiting for review on the clinic computer.');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'session' => $this->payload($session->refresh()),
            ], 201);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('upload.show', ['selector' => $selector]);
    }

    public function complete(
        Request $request,
        string $selector,
        QrUploadService $sessions,
        UploadAudienceService $audience,
    ): JsonResponse|RedirectResponse {
        $verifier = $this->verifier($request);
        $session = $this->resolve($sessions, $selector, $verifier);
        $audience->assertAllowed($request, $session);

        if ($session->documents()->count() < 1) {
            $message = __('Upload at least one file before completing the session.');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['files' => [$message]],
                ], 422);
            }

            return back()->withErrors([
                'files' => $message,
            ]);
        }

        $sessions->complete($session);
        $message = __('Upload complete. You can close this page.');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'status' => UploadSession::STATUS_COMPLETED,
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('upload.show', ['selector' => $selector]);
    }

    private function resolve(
        QrUploadService $sessions,
        string $selector,
        string $verifier,
    ): UploadSession {
        $session = $sessions->resolve($selector, $verifier);
        abort_unless($session instanceof UploadSession, 404);

        return $session;
    }

    private function verifier(Request $request): string
    {
        return $request->validate([
            'verifier' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
        ])['verifier'];
    }

    /** @return array<string, mixed> */
    private function payload(UploadSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'mode' => $session->mode,
            'status' => $session->status,
            'expires_at' => $session->expires_at->toIso8601String(),
            'remaining_seconds' => (int) max(0, now()->diffInSeconds($session->expires_at, false)),
            'maximum_files' => $session->maximum_files,
            'maximum_individual_bytes' => $session->maximum_individual_bytes,
            'maximum_total_bytes' => $session->maximum_total_bytes,
            'allowed_mime_types' => $session->allowed_mime_types,
            'files' => $session->status === UploadSession::STATUS_COMPLETED
                ? []
                : $session->documents()
                    ->orderBy('uploaded_at')
                    ->get(['id', 'original_name', 'size', 'status', 'uploaded_at'])
                    ->map(static fn (UploadedDocument $document): array => [
                        'id' => $document->getKey(),
                        'name' => $document->original_name,
                        'size' => $document->size,
                        'status' => $document->status,
                        'uploaded_at' => $document->uploaded_at?->toIso8601String(),
                    ])->values()->all(),
        ];
    }
}

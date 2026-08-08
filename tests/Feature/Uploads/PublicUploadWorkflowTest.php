<?php

namespace Tests\Feature\Uploads;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Document;
use App\Models\Patient;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\QrUploadService;
use App\Services\UploadDocumentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicUploadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'app.url' => 'http://192.168.1.40:8000',
            'medismart.runtime.lan_listener_status' => 'active',
        ]);
        URL::forceRootUrl('http://192.168.1.40:8000');
        app(ApplicationSettingService::class)->set(
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4,
            '192.168.1.40',
        );
    }

    public function test_the_public_page_exposes_only_upload_and_clinic_information(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'created_by' => $user->getKey(),
            'first_name' => 'Private',
            'last_name' => 'Patient',
        ]);
        $created = app(QrUploadService::class)->create('local', $user, $patient);
        [$selector, $verifier] = $this->credentials($created);

        $this->get(route('upload.show', ['selector' => $selector]))
            ->assertOk()
            ->assertSee($selector)
            ->assertDontSee('Private Patient')
            ->assertDontSee($verifier);

        $this->postJson(route('upload.session', ['selector' => $selector]), [
            'verifier' => $verifier,
        ])->assertOk()
            ->assertJsonPath('id', $created['session']->getKey())
            ->assertJsonPath('maximum_files', 10)
            ->assertJsonMissingPath('patient_id');
    }

    public function test_a_valid_pdf_is_stored_privately_and_waits_for_desktop_review(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Atlas',
            'status' => CabinetStatus::ACTIVE,
        ]);
        $user = User::factory()->create(['cabinet_id' => $cabinet->getKey()]);
        $created = app(QrUploadService::class)->create('local', $user);
        [$selector, $verifier] = $this->credentials($created);

        $this->post(route('upload.files.store', ['selector' => $selector]), [
            'verifier' => $verifier,
            'files' => [$this->pdf('analyse.pdf')],
        ])->assertRedirect(route('upload.show', ['selector' => $selector]));

        $uploaded = UploadedDocument::query()->sole();
        $this->assertSame($cabinet->getKey(), $created['session']->cabinet_id);
        $this->assertSame($cabinet->getKey(), $uploaded->cabinet_id);
        $this->assertSame(UploadedDocument::STATUS_PENDING_REVIEW, $uploaded->status);
        $this->assertSame('application/pdf', $uploaded->mime_type);
        $this->assertStringStartsWith('upload-quarantine/', $uploaded->path);
        Storage::disk('local')->assertExists($uploaded->path);
        $this->assertSame(UploadSession::STATUS_UPLOADING, $created['session']->refresh()->status);
        $this->assertSame(
            $cabinet->getKey(),
            AuditLog::withoutCabinetScope()
                ->where('action', 'upload.received')
                ->sole()
                ->cabinet_id,
        );
    }

    public function test_mime_extension_mismatches_and_executables_are_rejected(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector, $verifier] = $this->credentials($created);

        $this->from(route('upload.show', ['selector' => $selector]))
            ->post(route('upload.files.store', ['selector' => $selector]), [
                'verifier' => $verifier,
                'files' => [UploadedFile::fake()->create('malware.jpg', 2, 'application/x-dosexec')],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('files.0');

        $this->assertDatabaseCount('uploaded_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('upload-quarantine');
    }

    public function test_completing_the_session_invalidates_uploads_but_shows_a_success_page(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector, $verifier] = $this->credentials($created);
        $this->post(route('upload.files.store', ['selector' => $selector]), [
            'verifier' => $verifier,
            'files' => [$this->png('image.png')],
        ]);

        $this->post(route('upload.complete', ['selector' => $selector]), [
            'verifier' => $verifier,
        ])->assertRedirect(route('upload.show', ['selector' => $selector]));

        $this->assertSame(UploadSession::STATUS_COMPLETED, $created['session']->refresh()->status);
        $this->post(route('upload.files.store', ['selector' => $selector]), [
            'verifier' => $verifier,
            'files' => [$this->pdf('late.pdf')],
        ])->assertNotFound();
        $this->postJson(route('upload.session', ['selector' => $selector]), [
            'verifier' => $verifier,
        ])->assertNotFound();
    }

    public function test_an_administrator_can_accept_or_reject_pending_files(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);
        [$selector, $verifier] = $this->credentials($created);

        $this->post(route('upload.files.store', ['selector' => $selector]), [
            'verifier' => $verifier,
            'files' => [
                $this->pdf('keep.pdf'),
                $this->png('discard.png'),
            ],
        ]);

        $keep = UploadedDocument::query()->where('original_name', 'keep.pdf')->firstOrFail();
        $discard = UploadedDocument::query()->where('original_name', 'discard.png')->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.uploaded-documents.preview', $keep))
            ->assertOk();
        $this->actingAs($administrator)
            ->post(route('app.configuration.connectivity-backup.uploaded-documents.accept', $keep), [
                'patient_id' => $patient->getKey(),
            ])->assertRedirect();
        $this->actingAs($administrator)
            ->post(route('app.configuration.connectivity-backup.uploaded-documents.reject', $discard))
            ->assertRedirect();

        $keep->refresh();
        $discard->refresh();
        $this->assertSame(UploadedDocument::STATUS_ACCEPTED, $keep->status);
        $this->assertNotNull($keep->document_id);
        $this->assertSame(UploadedDocument::STATUS_REJECTED, $discard->status);
        $this->assertDatabaseHas('documents', [
            'id' => $keep->document_id,
            'patient_id' => $patient->getKey(),
            'category' => 'uploaded',
        ]);
        $this->assertSame($keep->path, Document::query()->findOrFail($keep->document_id)->file_path);
        Storage::disk('local')->assertExists($keep->path);
        Storage::disk('local')->assertMissing($discard->path);
    }

    public function test_a_stale_upload_cannot_reopen_a_revoked_session(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        $stale = UploadSession::query()->findOrFail($created['session']->getKey());
        app(QrUploadService::class)->revoke($created['session']);

        try {
            app(UploadDocumentService::class)->receive($stale, [$this->pdf('late.pdf')]);
            $this->fail('A stale upload must not reopen a revoked session.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(UploadSession::STATUS_REVOKED, $stale->refresh()->status);
        $this->assertDatabaseCount('uploaded_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('upload-quarantine');
    }

    public function test_a_bound_upload_cannot_be_accepted_for_another_patient(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patientA = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $patientB = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patientA);
        app(UploadDocumentService::class)->receive($created['session'], [$this->pdf('patient-a.pdf')]);
        $uploaded = UploadedDocument::query()->sole();

        $this->actingAs($administrator)
            ->post(route('app.configuration.connectivity-backup.uploaded-documents.accept', $uploaded), [
                'patient_id' => $patientB->getKey(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('patient_id');

        $this->assertSame(UploadedDocument::STATUS_PENDING_REVIEW, $uploaded->refresh()->status);
        $this->assertDatabaseCount('documents', 0);
        Storage::disk('local')->assertExists($uploaded->path);
    }

    public function test_preview_rechecks_integrity_and_stale_review_actions_cannot_overwrite_acceptance(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);
        app(UploadDocumentService::class)->receive($created['session'], [$this->pdf('review.pdf')]);
        $uploaded = UploadedDocument::query()->sole();
        $stale = UploadedDocument::query()->findOrFail($uploaded->getKey());

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.uploaded-documents.preview', $uploaded))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        app(UploadDocumentService::class)->accept($uploaded, $administrator, $patient);

        try {
            app(UploadDocumentService::class)->reject($stale, $administrator);
            $this->fail('A stale rejection must not overwrite an accepted document.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $accepted = $uploaded->refresh();
        $this->assertSame(UploadedDocument::STATUS_ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->document_id);
        Storage::disk('local')->assertExists($accepted->path);
    }

    public function test_tampered_or_out_of_quarantine_files_cannot_be_accepted_or_deleted(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);
        app(UploadDocumentService::class)->receive($created['session'], [$this->pdf('tamper.pdf')]);
        $uploaded = UploadedDocument::query()->sole();
        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.uploaded-documents.preview', $uploaded))
            ->assertOk();
        Storage::disk('local')->put($uploaded->path, 'tampered');

        try {
            app(UploadDocumentService::class)->accept($uploaded, $administrator, $patient);
            $this->fail('A tampered quarantine file must not be accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $managedPath = 'patient-documents/'.$patient->getKey().'/do-not-delete.pdf';
        Storage::disk('local')->put($managedPath, 'managed');
        $uploaded->refresh()->update(['path' => $managedPath]);

        try {
            app(UploadDocumentService::class)->reject($uploaded->refresh(), $administrator);
            $this->fail('Reject must never delete a path outside quarantine.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        Storage::disk('local')->assertExists($managedPath);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_only_sensitive_settings_managers_can_create_or_review_sessions(): void
    {
        config(['medismart.runtime.lan_listener_status' => 'active']);
        app(ApplicationSettingService::class)->setMany([
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4 => '192.168.1.40',
            ApplicationSettingRegistry::CONNECTIVITY_LAN_ENABLED => true,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('app.configuration.connectivity-backup.upload-sessions.store'), ['mode' => 'local'])
            ->assertForbidden();

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $response = $this->actingAs($administrator)
            ->post(route('app.configuration.connectivity-backup.upload-sessions.store'), [
                'mode' => 'local',
                'patient_id' => $patient->getKey(),
            ]);

        $response
            ->assertRedirect()
            ->assertCookie('medismart_active_upload')
            ->assertSessionMissing('active_upload');
        $this->assertStringNotContainsString(
            '#v=',
            (string) $response->headers->get('Set-Cookie'),
        );
        $this->assertStringNotContainsString(
            '/upload/',
            (string) $response->headers->get('Set-Cookie'),
        );

        $this->assertDatabaseCount('upload_sessions', 1);
    }

    public function test_a_forged_local_qr_request_is_rejected_without_a_verified_listener(): void
    {
        app(ApplicationSettingService::class)->setMany([
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4 => '192.168.1.40',
            ApplicationSettingRegistry::CONNECTIVITY_LAN_ENABLED => true,
        ]);
        config(['medismart.runtime.lan_listener_status' => 'stopped']);
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);

        $this->actingAs($administrator)
            ->post(route('app.configuration.connectivity-backup.upload-sessions.store'), [
                'mode' => 'local',
                'patient_id' => $patient->getKey(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('mode');

        $this->assertDatabaseCount('upload_sessions', 0);
    }

    public function test_a_local_token_cannot_be_replayed_through_another_host(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector, $verifier] = $this->credentials($created);

        $this->call(
            'POST',
            'http://remote.example.test'.route(
                'upload.session',
                ['selector' => $selector],
                false,
            ),
            ['verifier' => $verifier],
            server: [
                'HTTP_HOST' => 'remote.example.test',
                'SERVER_NAME' => 'remote.example.test',
                'SERVER_PORT' => 80,
            ],
        )->assertNotFound();

        $this->call(
            'POST',
            'http://192.168.1.40:8000'.route(
                'upload.session',
                ['selector' => $selector],
                false,
            ),
            ['verifier' => $verifier],
            server: [
                'HTTP_HOST' => '192.168.1.40:8000',
                'SERVER_NAME' => '192.168.1.40',
                'SERVER_PORT' => 8000,
            ],
        )->assertOk();
    }

    public function test_brute_force_limits_are_isolated_per_public_selector(): void
    {
        $user = User::factory()->create();
        $first = app(QrUploadService::class)->create('local', $user);
        $second = app(QrUploadService::class)->create('local', $user);
        [$firstSelector, $firstVerifier] = $this->credentials($first);
        [$secondSelector, $secondVerifier] = $this->credentials($second);
        $wrongVerifier = str_repeat($firstVerifier === str_repeat('z', 43) ? 'y' : 'z', 43);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson(route('upload.session', ['selector' => $firstSelector]), [
                'verifier' => $wrongVerifier,
            ])->assertNotFound();
        }

        $this->postJson(route('upload.session', ['selector' => $firstSelector]), [
            'verifier' => $wrongVerifier,
        ])->assertTooManyRequests();

        $this->postJson(route('upload.session', ['selector' => $secondSelector]), [
            'verifier' => $secondVerifier,
        ])->assertOk();
    }

    /** @param array{token: string} $created
     * @return array{string, string}
     */
    private function credentials(array $created): array
    {
        $parts = explode('.', $created['token'], 2);
        $this->assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        );
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
    }
}

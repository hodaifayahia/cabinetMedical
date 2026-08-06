<?php

namespace Tests\Feature\Uploads;

use App\Models\ApplicationEvent;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\QrUploadService;
use App\Services\UploadDocumentService;
use App\Services\UploadReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_expires_credentials_and_deletes_only_provably_disposable_files(): void
    {
        $created = app(QrUploadService::class)->create(
            'local',
            User::factory()->create(),
            options: ['expires_after_minutes' => 1],
        );
        $session = $created['session'];
        $originalTokenHash = $session->public_token_hash;
        $pending = app(UploadDocumentService::class)->receive($session, [
            $this->pdf('pending-review.pdf'),
        ])[0];

        $missingName = Str::uuid().'.pdf';
        $session->documents()->create([
            'original_name' => 'missing.pdf',
            'stored_name' => $missingName,
            'disk' => 'local',
            'path' => 'upload-quarantine/'.$session->getKey().'/'.$missingName,
            'mime_type' => 'application/pdf',
            'size' => 42,
            'sha256' => str_repeat('0', 64),
            'status' => UploadedDocument::STATUS_PENDING_REVIEW,
            'uploaded_at' => now(),
        ]);

        $rejectedPath = 'upload-rejected/'.$session->getKey().'/discard.discard';
        Storage::disk('local')->put($rejectedPath, 'discard');
        $session->documents()->create([
            'original_name' => 'discard.pdf',
            'stored_name' => 'discard.pdf',
            'disk' => 'local',
            'path' => $rejectedPath,
            'mime_type' => 'application/pdf',
            'size' => 7,
            'sha256' => hash('sha256', 'discard'),
            'status' => UploadedDocument::STATUS_REJECTED,
            'uploaded_at' => now(),
        ]);

        $orphanPath = 'upload-quarantine/'.$session->getKey().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($orphanPath, 'orphan');
        touch(
            Storage::disk('local')->path($orphanPath),
            now()->subDays(2)->getTimestamp(),
        );
        $acceptedPath = 'patient-documents/never-delete.pdf';
        Storage::disk('local')->put($acceptedPath, 'clinical document');

        $this->travel(2)->minutes();
        $report = app(UploadReconciliationService::class)->reconcile(
            CarbonImmutable::now(),
        );

        $this->assertSame(1, $report['sessions_expired']);
        $this->assertSame(1, $report['rejected_files_deleted']);
        $this->assertSame(1, $report['orphan_files_deleted']);
        $this->assertSame(1, $report['attention_required']);
        $this->assertSame(UploadSession::STATUS_EXPIRED, $session->refresh()->status);
        $this->assertNotSame($originalTokenHash, $session->public_token_hash);
        Storage::disk('local')->assertExists($pending->path);
        Storage::disk('local')->assertMissing($rejectedPath);
        Storage::disk('local')->assertMissing($orphanPath);
        Storage::disk('local')->assertExists($acceptedPath);
        $this->assertDatabaseHas('application_events', [
            'event' => 'UploadReconciliationRequired',
            'severity' => 'warning',
        ]);
        $this->assertSame(
            'quarantine_file_missing',
            ApplicationEvent::query()
                ->where('event', 'UploadReconciliationRequired')
                ->firstOrFail()
                ->context['reason'],
        );
    }

    public function test_the_reconciliation_command_is_registered(): void
    {
        $this->artisan('medismart:uploads:reconcile')
            ->expectsOutputToContain('Upload reconciliation completed')
            ->assertSuccessful();
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        );
    }
}

<?php

declare(strict_types=1);

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\QrUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

const FIXTURE_EMAIL = 'public-upload.fixture@medismart.test';
const FIXTURE_PURPOSE = 'playwright_public_upload';

$projectRoot = dirname(__DIR__, 3);

require $projectRoot.'/vendor/autoload.php';

$application = require $projectRoot.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$normalizePath = static fn (string $path): string => str_replace(
    '\\',
    '/',
    realpath($path) ?: $path,
);
$expectedDatabase = $normalizePath(
    $projectRoot.'/storage/framework/testing/playwright/database.sqlite',
);
$expectedStorage = $normalizePath(
    $projectRoot.'/storage/framework/testing/playwright/laravel-storage',
);
$actualDatabase = $normalizePath(
    (string) config('database.connections.sqlite.database'),
);
$actualStorage = $normalizePath(storage_path());

if (! $application->environment('e2e')
    || $actualDatabase !== $expectedDatabase
    || $actualStorage !== $expectedStorage) {
    fwrite(STDERR, "Refusing to use a non-isolated Playwright runtime.\n");
    exit(1);
}

/**
 * Delete only records and quarantined files carrying the dedicated fixture
 * marker. The first-run owner, when one exists, is deliberately untouched.
 */
$cleanup = static function (): void {
    $sessionIds = UploadSession::query()
        ->where('purpose', FIXTURE_PURPOSE)
        ->pluck('id')
        ->all();
    $documents = $sessionIds === []
        ? collect()
        : UploadedDocument::query()
            ->whereIn('upload_session_id', $sessionIds)
            ->get();
    $documentIds = $documents->pluck('id')->all();

    foreach ($documents as $document) {
        if ($document->disk === 'local') {
            Storage::disk('local')->delete($document->path);
        }
    }

    foreach ($sessionIds as $sessionId) {
        Storage::disk('local')->deleteDirectory(
            'upload-quarantine/'.$sessionId,
        );
    }

    DB::transaction(static function () use (
        $documentIds,
        $sessionIds,
    ): void {
        $subjectIds = array_values(array_merge($documentIds, $sessionIds));

        if ($subjectIds !== []) {
            AuditLog::query()->whereIn('subject_id', $subjectIds)->delete();
        }

        foreach ($sessionIds as $sessionId) {
            ApplicationEvent::query()
                ->where('context', 'like', '%'.$sessionId.'%')
                ->delete();
        }

        if ($sessionIds !== []) {
            UploadedDocument::query()
                ->whereIn('upload_session_id', $sessionIds)
                ->delete();
            UploadSession::query()->whereIn('id', $sessionIds)->delete();
        }

        $fixtureUserIds = User::query()
            ->where('email', FIXTURE_EMAIL)
            ->pluck('id')
            ->all();

        if ($fixtureUserIds !== []) {
            AuditLog::query()->whereIn('user_id', $fixtureUserIds)->delete();
            User::query()->whereIn('id', $fixtureUserIds)->delete();
        }
    });

    if (UploadSession::query()->where('purpose', FIXTURE_PURPOSE)->exists()
        || User::query()->where('email', FIXTURE_EMAIL)->exists()) {
        throw new RuntimeException('The public upload fixture cleanup is incomplete.');
    }
};

try {
    $operation = $argv[1] ?? '';

    if ($operation === 'cleanup') {
        $cleanup();
        echo "{}\n";
        exit(0);
    }

    if ($operation !== 'create') {
        throw new InvalidArgumentException('Expected create or cleanup.');
    }

    $cleanup();

    $creator = User::query()->create([
        'name' => 'Public Upload Playwright Fixture',
        'email' => FIXTURE_EMAIL,
        'email_verified_at' => now(),
        'password' => Hash::make('not-used-by-the-browser-test'),
    ]);
    $created = app(QrUploadService::class)->create(
        mode: 'local',
        creator: $creator,
        purpose: FIXTURE_PURPOSE,
        options: [
            'expires_after_minutes' => 10,
            'maximum_files' => 1,
            'maximum_individual_bytes' => 64 * 1024,
            'maximum_total_bytes' => 64 * 1024,
            'allowed_mime_types' => ['application/pdf'],
        ],
    );
    [$selector, $verifier] = explode('.', $created['token'], 2);

    echo json_encode([
        'selector' => $selector,
        'verifier' => $verifier,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Public upload fixture failed: '.$exception->getMessage()."\n");
    exit(1);
}

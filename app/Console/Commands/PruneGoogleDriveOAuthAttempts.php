<?php

namespace App\Console\Commands;

use App\Models\GoogleDriveOAuthAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneGoogleDriveOAuthAttempts extends Command
{
    protected $signature = 'medismart:oauth-attempts:prune';

    protected $description = 'Expire stale Google Drive OAuth attempts and prune old terminal metadata';

    public function handle(): int
    {
        $counts = DB::transaction(static function (): array {
            $expired = GoogleDriveOAuthAttempt::query()
                ->where('status', GoogleDriveOAuthAttempt::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => GoogleDriveOAuthAttempt::STATUS_EXPIRED,
                    'encrypted_pkce_verifier' => null,
                    'failed_at' => now(),
                    'failure_code' => 'expired',
                ]);
            $abandoned = GoogleDriveOAuthAttempt::query()
                ->where('status', GoogleDriveOAuthAttempt::STATUS_CLAIMED)
                ->where('expires_at', '<=', now()->subMinutes(5))
                ->update([
                    'status' => GoogleDriveOAuthAttempt::STATUS_FAILED,
                    'encrypted_pkce_verifier' => null,
                    'failed_at' => now(),
                    'failure_code' => 'claim_timeout',
                ]);
            $pruned = GoogleDriveOAuthAttempt::query()
                ->whereIn('status', [
                    GoogleDriveOAuthAttempt::STATUS_COMPLETED,
                    GoogleDriveOAuthAttempt::STATUS_FAILED,
                    GoogleDriveOAuthAttempt::STATUS_EXPIRED,
                ])
                ->where('updated_at', '<=', now()->subDays(7))
                ->delete();

            return compact('expired', 'abandoned', 'pruned');
        });

        $this->components->info(sprintf(
            'OAuth attempts: %d expired, %d abandoned, %d old terminal rows pruned.',
            $counts['expired'],
            $counts['abandoned'],
            $counts['pruned'],
        ));

        return self::SUCCESS;
    }
}

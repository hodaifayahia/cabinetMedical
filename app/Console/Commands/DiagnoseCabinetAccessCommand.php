<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Explains why an account cannot get into its cabinet.
 *
 * "Sign-in is not working" is almost never a credentials problem. Far more
 * often the credentials are accepted and the member is then held at the
 * activation or approval screen by EnsureCabinetIsActive, or stopped by the
 * `verified` middleware before the dashboard. From the outside those look
 * identical to a rejected password, so this prints the actual reason.
 *
 * Deliberately read-only: it changes nothing, so it is safe to run on a
 * production server while a customer is on the phone.
 */
class DiagnoseCabinetAccessCommand extends Command
{
    protected $signature = 'cabinet:diagnose {email? : Only report on this account}';

    protected $description = 'Explain why an account cannot reach its cabinet (read-only)';

    public function handle(CabinetAccessService $access): int
    {
        $email = $this->argument('email');

        $users = User::query()
            ->when($email !== null, fn ($query) => $query->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $email))]))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->error($email === null
                ? 'There are no accounts in this database at all.'
                : "No account exists for {$email}.");

            if ($email !== null) {
                $this->newLine();
                $this->line('If you believe you registered this address, the registration did not');
                $this->line('complete. Check storage/logs/laravel.log around the time you signed up,');
                $this->line('and confirm no migrations are pending with: php artisan migrate:status');
            }

            return self::FAILURE;
        }

        $rows = [];
        $blocked = 0;

        foreach ($users as $user) {
            $reason = $access->denialReason($user);
            $unverified = $user->email_verified_at === null && ! $user->is_platform_admin;
            $roleless = ! $user->is_platform_admin
                && $user->cabinet_id !== null
                && $user->roles()->count() === 0;

            $problems = array_filter([
                $reason,
                // Both of these stop a member after the cabinet gate has
                // already let them through, which is why they are easy to miss.
                $unverified ? 'email_not_verified' : null,
                $roleless ? 'no_role_assigned' : null,
            ]);

            if ($problems !== []) {
                $blocked++;
            }

            // Held in a local so the null check is explicit: a platform
            // administrator has no cabinet, even though the relation is typed
            // as though it always resolves.
            $cabinet = $user->cabinet_id === null ? null : $user->cabinet;

            $rows[] = [
                $user->email,
                $cabinet === null ? '(none)' : $cabinet->name,
                $cabinet === null ? '-' : $cabinet->status->value,
                $cabinet === null ? '-' : ($cabinet->license?->effectiveStatus() ?? '-'),
                $problems === [] ? 'CAN ENTER' : implode(', ', $problems),
            ];
        }

        $this->table(['Account', 'Cabinet', 'Status', 'Licence', 'Verdict'], $rows);

        if ($blocked === 0) {
            $this->info('Every account listed can reach its cabinet.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn($blocked.' account(s) cannot reach their cabinet. What each verdict means:');
        $this->newLine();

        foreach ($this->explanations() as $code => $meaning) {
            $this->line("  <comment>{$code}</comment>");
            $this->line('    '.$meaning);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function explanations(): array
    {
        return [
            CabinetAccessService::REASON_CABINET_PENDING => 'The cabinet has never been activated. Redeem an activation code, or on a Hub run php artisan hub:activate <file>.',
            CabinetAccessService::REASON_LICENSE_EXPIRED => 'The licence ran out. A 7-day trial expires silently and everyone is locked out at once. Renew or upgrade it from the platform admin.',
            CabinetAccessService::REASON_LICENSE_INACTIVE => 'The licence exists but is not active (revoked or suspended). Check it in the platform admin.',
            CabinetAccessService::REASON_CABINET_SUSPENDED => 'The cabinet was suspended by platform staff.',
            CabinetAccessService::REASON_AWAITING_APPROVAL => 'The member joined but the cabinet owner has not approved them yet. Approve them under staff management, which also assigns their role.',
            'email_not_verified' => 'The dashboard sits behind the `verified` middleware, so this account is redirected to the verification notice however valid its password is.',
            'no_role_assigned' => 'The account has no role, so every permission check fails once it is inside. Approving a pending member assigns one; an account created another way may have none.',
        ];
    }
}

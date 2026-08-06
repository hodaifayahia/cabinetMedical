<?php

namespace Tests\Unit\Backups;

use App\Backups\BackupRetentionPlanner;
use App\Backups\BackupRetentionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupRetentionPlannerTest extends TestCase
{
    #[Test]
    public function it_keeps_the_union_of_newest_daily_weekly_and_monthly_utc_buckets(): void
    {
        $entries = [
            $this->entry('file-0', '2026-08-05T20:00:00Z', 0),
            $this->entry('file-1', '2026-08-05T10:00:00Z', 1),
            $this->entry('file-2', '2026-08-04T12:00:00Z', 2),
            $this->entry('file-3', '2026-08-03T12:00:00Z', 3),
            $this->entry('file-4', '2026-07-28T12:00:00Z', 4),
            $this->entry('file-5', '2026-07-01T12:00:00Z', 5),
            $this->entry('file-6', '2026-06-15T12:00:00Z', 6),
        ];

        $plan = (new BackupRetentionPlanner)->plan(
            $entries,
            new BackupRetentionPolicy(daily: 3, weekly: 2, monthly: 2),
        )->toArray();

        $this->assertSame(
            ['file-0', 'file-2', 'file-3', 'file-4'],
            array_column($plan['keep'], 'managed_file_id'),
        );
        $this->assertSame(
            ['file-6', 'file-5', 'file-1'],
            array_column($plan['deletion_candidates'], 'managed_file_id'),
        );
        $this->assertSame(
            ['newest', 'daily', 'weekly', 'monthly'],
            $plan['keep'][0]['reasons'],
        );
        $this->assertSame('2026-W32', $plan['keep'][0]['buckets']['weekly']);
        $this->assertSame('outside_retention_buckets', $plan['deletion_candidates'][0]['reason_code']);
    }

    #[Test]
    public function utc_conversion_controls_day_and_iso_week_buckets(): void
    {
        $entries = [
            $this->entry('file-a', '2026-08-05T00:30:00+01:00', 1),
            $this->entry('file-b', '2026-08-04T23:45:00Z', 2),
            $this->entry('file-c', '2026-08-05T00:15:00Z', 3),
            $this->entry('file-d', '2026-01-04T23:30:00-02:00', 4),
        ];
        $planner = new BackupRetentionPlanner;

        $daily = $planner->plan(
            $entries,
            new BackupRetentionPolicy(daily: 2, weekly: 0, monthly: 0),
        )->toArray();
        $this->assertSame(['file-c', 'file-b'], array_column($daily['keep'], 'managed_file_id'));
        $this->assertSame('2026-08-04', $daily['keep'][1]['buckets']['daily']);

        $weekly = $planner->plan(
            $entries,
            new BackupRetentionPolicy(daily: 0, weekly: 2, monthly: 0),
        )->toArray();
        $byId = collect($weekly['keep'])->keyBy('managed_file_id');
        $this->assertSame('2026-W02', $byId['file-d']['buckets']['weekly']);
        $this->assertSame('2026-01-05T01:30:00.000000000Z', $byId['file-d']['created_at_utc']);
    }

    #[Test]
    public function exact_rows_are_collapsed_and_duplicate_logical_backups_have_one_canonical_copy(): void
    {
        $canonical = $this->entry('file-a', '2026-08-05T12:00:00Z', 1);
        $duplicateFile = $canonical;
        $duplicateFile['id'] = 'file-b';
        $duplicateFile['name'] = 'file-b.msbackup';
        $entries = [
            $canonical,
            $canonical,
            $duplicateFile,
            $this->entry('file-c', '2026-07-01T12:00:00Z', 2),
        ];

        $plan = (new BackupRetentionPlanner)->plan(
            $entries,
            new BackupRetentionPolicy(daily: 0, weekly: 0, monthly: 0),
        )->toArray();
        $candidates = collect($plan['deletion_candidates'])->keyBy('managed_file_id');

        $this->assertSame(['file-a'], array_column($plan['keep'], 'managed_file_id'));
        $this->assertSame(1, $plan['summary']['collapsed_duplicate_input_count']);
        $this->assertSame('redundant_backup_record_duplicate', $candidates['file-b']['reason_code']);
        $this->assertSame('file-a', $candidates['file-b']['duplicate_of_managed_file_id']);
        $this->assertSame('outside_retention_buckets', $candidates['file-c']['reason_code']);
    }

    #[Test]
    public function conflicting_duplicate_identifiers_are_protected_and_never_candidates(): void
    {
        $sameIdLeft = $this->entry('file-a', '2026-08-05T12:00:00Z', 1);
        $sameIdRight = $sameIdLeft;
        $sameIdRight['sha256'] = hash('sha256', 'conflicting-managed-file');
        $sameIdRight['verified_sha256'] = $sameIdRight['sha256'];
        $sameRecordLeft = $this->entry('file-b', '2026-08-04T12:00:00Z', 2);
        $sameRecordRight = $this->entry('file-c', '2026-08-04T12:00:00Z', 3);
        $sameRecordRight['backup_record_id'] = $sameRecordLeft['backup_record_id'];

        $plan = (new BackupRetentionPlanner)->plan(
            [$sameIdLeft, $sameIdRight, $sameRecordLeft, $sameRecordRight],
            new BackupRetentionPolicy(daily: 0, weekly: 0, monthly: 0),
        )->toArray();

        $this->assertSame([], $plan['deletion_candidates']);
        $this->assertSame([], $plan['keep']);
        $this->assertSame(
            [
                'conflicting_managed_file_id',
                'conflicting_managed_file_id',
                'conflicting_backup_record_id',
                'conflicting_backup_record_id',
            ],
            array_column($plan['protected'], 'reason_code'),
        );
    }

    #[Test]
    public function malformed_and_unverified_rows_are_only_reported_as_protected(): void
    {
        $unverified = $this->entry('unsafe-a', '2026-08-08T12:00:00Z', 1);
        $unverified['verification_status'] = 'pending';
        $mismatched = $this->entry('unsafe-b', '2026-08-07T12:00:00Z', 2);
        $mismatched['verified_sha256'] = str_repeat('0', 64);
        $badDate = $this->entry('unsafe-c', '2026-02-30T12:00:00Z', 3);
        $entries = [
            '<script>not metadata</script>',
            $unverified,
            $mismatched,
            $badDate,
            $this->entry('safe-new', '2026-08-05T12:00:00Z', 4),
            $this->entry('safe-old', '2026-07-05T12:00:00Z', 5),
        ];

        $plan = (new BackupRetentionPlanner)->plan(
            $entries,
            new BackupRetentionPolicy(daily: 0, weekly: 0, monthly: 0),
        )->toArray();
        $encoded = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->assertSame(['safe-new'], array_column($plan['keep'], 'managed_file_id'));
        $this->assertSame(['safe-old'], array_column($plan['deletion_candidates'], 'managed_file_id'));
        $this->assertSame(
            ['malformed_metadata', 'unverified_metadata', 'unverified_metadata', 'malformed_metadata'],
            array_column($plan['protected'], 'reason_code'),
        );
        $this->assertStringNotContainsString('<script>', $encoded);
    }

    #[Test]
    public function planning_is_deterministic_for_the_same_verified_set(): void
    {
        $entries = [
            $this->entry('file-z', '2026-08-05T12:00:00.123456789Z', 1),
            $this->entry('file-a', '2026-08-05T12:00:00.123456789Z', 2),
            $this->entry('file-m', '2026-07-05T12:00:00Z', 3),
        ];
        $planner = new BackupRetentionPlanner;
        $policy = new BackupRetentionPolicy(daily: 1, weekly: 1, monthly: 1);
        $first = $planner->plan($entries, $policy)->toArray();
        $second = $planner->plan(array_reverse($entries), $policy)->toArray();

        $this->assertSame('file-a', $first['keep'][0]['managed_file_id']);
        $this->assertSame($first['plan_sha256'], $second['plan_sha256']);
        $this->assertSame($first['keep'], $second['keep']);
        $this->assertSame($first['deletion_candidates'], $second['deletion_candidates']);
    }

    #[Test]
    public function a_plan_is_non_authorizing_and_requires_revalidation_and_confirmation(): void
    {
        $plan = (new BackupRetentionPlanner)->plan(
            [
                $this->entry('file-new', '2026-08-05T12:00:00Z', 1),
                $this->entry('file-old', '2026-07-05T12:00:00Z', 2),
            ],
            new BackupRetentionPolicy(daily: 0, weekly: 0, monthly: 0),
        )->toArray();

        $this->assertFalse($plan['destructive_actions_performed']);
        $this->assertFalse($plan['deletion_authorized']);
        $this->assertTrue($plan['requires_exact_managed_file_revalidation']);
        $this->assertTrue($plan['requires_explicit_confirmation']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $plan['plan_sha256']);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $plan['deletion_candidates'][0]['metadata_fingerprint'],
        );
    }

    #[Test]
    public function maximum_storage_removes_oldest_tier_keeps_but_never_the_newest_or_protected_bytes(): void
    {
        $entries = [
            $this->entry('file-new', '2026-08-05T12:00:00Z', 3),
            $this->entry('file-middle', '2026-08-04T12:00:00Z', 2),
            $this->entry('file-old', '2026-08-03T12:00:00Z', 1),
        ];
        $protectedBytes = 500;
        $limit = $protectedBytes + 1027 + 1026;
        $plan = (new BackupRetentionPlanner)->plan(
            $entries,
            new BackupRetentionPolicy(3, 0, 0, $limit),
            $protectedBytes,
        )->toArray();

        $this->assertSame(
            ['file-new', 'file-middle'],
            array_column($plan['keep'], 'managed_file_id'),
        );
        $this->assertSame(['file-old'], array_column($plan['deletion_candidates'], 'managed_file_id'));
        $this->assertSame('maximum_storage_bytes', $plan['deletion_candidates'][0]['reason_code']);
        $this->assertSame($protectedBytes, $plan['summary']['protected_storage_bytes']);
        $this->assertSame($limit, $plan['summary']['projected_storage_bytes']);
        $this->assertTrue($plan['summary']['maximum_storage_satisfied']);

        $unsatisfied = (new BackupRetentionPlanner)->plan(
            $entries,
            new BackupRetentionPolicy(3, 0, 0, $protectedBytes + 1026),
            $protectedBytes,
        )->toArray();
        $this->assertSame(['file-new'], array_column($unsatisfied['keep'], 'managed_file_id'));
        $this->assertFalse($unsatisfied['summary']['maximum_storage_satisfied']);
    }

    #[Test]
    public function verified_v1_archives_are_supported_for_scheduled_local_retention(): void
    {
        $v1 = $this->entry('scheduled-v1', '2026-08-05T12:00:00Z', 1);
        $v1['format_version'] = 1;
        $plan = (new BackupRetentionPlanner)->plan(
            [$v1],
            new BackupRetentionPolicy(0, 0, 0),
        )->toArray();

        $this->assertSame(['scheduled-v1'], array_column($plan['keep'], 'managed_file_id'));
        $this->assertSame(1, $plan['keep'][0]['format_version']);
    }

    #[Test]
    public function retention_counts_are_bounded_but_each_tier_can_be_disabled(): void
    {
        $disabled = new BackupRetentionPolicy(daily: 0, weekly: 0, monthly: 0);
        $this->assertSame(0, $disabled->daily);

        foreach ([
            [-1, 0, 0],
            [BackupRetentionPolicy::MAXIMUM_DAILY_BUCKETS + 1, 0, 0],
            [0, BackupRetentionPolicy::MAXIMUM_WEEKLY_BUCKETS + 1, 0],
            [0, 0, BackupRetentionPolicy::MAXIMUM_MONTHLY_BUCKETS + 1],
        ] as [$daily, $weekly, $monthly]) {
            try {
                new BackupRetentionPolicy($daily, $weekly, $monthly);
                $this->fail('An unsafe retention count must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([0, BackupRetentionPolicy::MAXIMUM_STORAGE_BYTES + 1] as $maximumStorageBytes) {
            try {
                new BackupRetentionPolicy(0, 0, 0, $maximumStorageBytes);
                $this->fail('An unsafe storage limit must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, bool|int|string> */
    private function entry(string $id, string $createdAt, int $number): array
    {
        $sha256 = hash('sha256', 'verified-backup-'.$number);

        return [
            'id' => $id,
            'name' => $id.'.msbackup',
            'size_bytes' => 1024 + $number,
            'created_at' => $createdAt,
            'sha256' => $sha256,
            'backup_record_id' => '00000000-0000-4000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT),
            'format' => 'msbackup',
            'format_version' => 2,
            'verification_status' => 'verified',
            'verified_sha256' => $sha256,
        ];
    }
}

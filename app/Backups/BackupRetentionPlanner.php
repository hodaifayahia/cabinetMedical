<?php

namespace App\Backups;

use JsonException;

final class BackupRetentionPlanner
{
    /**
     * The input is deliberately untrusted. Only entries carrying a complete,
     * matching verification contract can ever become deletion candidates.
     *
     * @param  iterable<mixed>  $rawEntries
     */
    public function plan(
        iterable $rawEntries,
        BackupRetentionPolicy $policy,
        int $protectedStorageBytes = 0,
    ): BackupRetentionPlan {
        if ($protectedStorageBytes < 0) {
            throw new BackupArchiveException('Protected backup storage bytes cannot be negative.');
        }

        $inputCount = 0;
        $parsed = [];
        $protected = [];

        foreach ($rawEntries as $raw) {
            $inputIndex = $inputCount++;
            $result = VerifiedBackupMetadata::fromUntrusted($raw);

            if ($result['metadata'] === null) {
                $protected[] = [
                    'input_index' => $inputIndex,
                    'reason_code' => $result['reason_code'],
                ];

                continue;
            }

            $parsed[] = [
                'metadata' => $result['metadata'],
                'input_indexes' => [$inputIndex],
            ];
        }

        /** @var array<string, list<array{metadata: VerifiedBackupMetadata, input_indexes: list<int>}>> $byManagedId */
        $byManagedId = [];

        foreach ($parsed as $row) {
            $byManagedId[$row['metadata']->managedFileId][] = $row;
        }

        ksort($byManagedId, SORT_STRING);
        $uniqueManagedFiles = [];

        foreach ($byManagedId as $rows) {
            $first = $rows[0]['metadata'];
            $conflicting = false;

            foreach ($rows as $row) {
                if (! $first->hasSameManagedMetadata($row['metadata'])) {
                    $conflicting = true;
                    break;
                }
            }

            if ($conflicting) {
                foreach ($rows as $row) {
                    foreach ($row['input_indexes'] as $inputIndex) {
                        $protected[] = [
                            'input_index' => $inputIndex,
                            'reason_code' => 'conflicting_managed_file_id',
                        ];
                    }
                }

                continue;
            }

            $inputIndexes = [];

            foreach ($rows as $row) {
                array_push($inputIndexes, ...$row['input_indexes']);
            }

            sort($inputIndexes, SORT_NUMERIC);
            $uniqueManagedFiles[] = [
                'metadata' => $first,
                'input_indexes' => $inputIndexes,
            ];
        }

        /** @var array<string, list<array{metadata: VerifiedBackupMetadata, input_indexes: list<int>}>> $byBackupRecord */
        $byBackupRecord = [];

        foreach ($uniqueManagedFiles as $row) {
            $byBackupRecord[$row['metadata']->backupRecordId][] = $row;
        }

        ksort($byBackupRecord, SORT_STRING);
        $logicalBackups = [];
        $redundantFiles = [];
        $collapsedDuplicateInputs = 0;

        foreach ($byBackupRecord as $rows) {
            $first = $rows[0]['metadata'];
            $conflicting = false;

            foreach ($rows as $row) {
                if (! $first->representsSameLogicalBackup($row['metadata'])) {
                    $conflicting = true;
                    break;
                }
            }

            if ($conflicting) {
                foreach ($rows as $row) {
                    foreach ($row['input_indexes'] as $inputIndex) {
                        $protected[] = [
                            'input_index' => $inputIndex,
                            'reason_code' => 'conflicting_backup_record_id',
                        ];
                    }
                }

                continue;
            }

            usort(
                $rows,
                static fn (array $left, array $right): int => VerifiedBackupMetadata::compareNewest(
                    $left['metadata'],
                    $right['metadata'],
                ),
            );
            $canonical = array_shift($rows);

            if (! is_array($canonical)) {
                continue;
            }

            $logicalBackups[] = $canonical;
            $collapsedDuplicateInputs += count($canonical['input_indexes']) - 1;

            foreach ($rows as $redundant) {
                $collapsedDuplicateInputs += count($redundant['input_indexes']) - 1;
                $redundantFiles[] = [
                    'metadata' => $redundant['metadata'],
                    'duplicate_of' => $canonical['metadata']->managedFileId,
                ];
            }
        }

        usort(
            $logicalBackups,
            static fn (array $left, array $right): int => VerifiedBackupMetadata::compareNewest(
                $left['metadata'],
                $right['metadata'],
            ),
        );

        /** @var array<string, array<string, bool>> $keepReasons */
        $keepReasons = [];
        /** @var array<string, array<string, string>> $keepBuckets */
        $keepBuckets = [];

        if ($logicalBackups !== []) {
            $newestId = $logicalBackups[0]['metadata']->managedFileId;
            $keepReasons[$newestId]['newest'] = true;
        }

        foreach ([
            'daily' => $policy->daily,
            'weekly' => $policy->weekly,
            'monthly' => $policy->monthly,
        ] as $tier => $limit) {
            if ($limit === 0) {
                continue;
            }

            $selectedBuckets = [];

            foreach ($logicalBackups as $row) {
                $metadata = $row['metadata'];
                $bucket = match ($tier) {
                    'daily' => $metadata->dailyBucket(),
                    'weekly' => $metadata->weeklyBucket(),
                    'monthly' => $metadata->monthlyBucket(),
                };

                if (isset($selectedBuckets[$bucket])) {
                    continue;
                }

                if (count($selectedBuckets) >= $limit) {
                    break;
                }

                $selectedBuckets[$bucket] = true;
                $keepReasons[$metadata->managedFileId][$tier] = true;
                $keepBuckets[$metadata->managedFileId][$tier] = $bucket;
            }
        }

        $currentStorageBytes = $protectedStorageBytes;

        foreach ($logicalBackups as $row) {
            $currentStorageBytes = $this->addBytes(
                $currentStorageBytes,
                $row['metadata']->sizeBytes,
            );
        }
        foreach ($redundantFiles as $row) {
            $currentStorageBytes = $this->addBytes(
                $currentStorageBytes,
                $row['metadata']->sizeBytes,
            );
        }

        $projectedStorageBytes = $protectedStorageBytes;

        foreach ($logicalBackups as $row) {
            if (isset($keepReasons[$row['metadata']->managedFileId])) {
                $projectedStorageBytes = $this->addBytes(
                    $projectedStorageBytes,
                    $row['metadata']->sizeBytes,
                );
            }
        }

        $storageLimitCandidates = [];

        if ($policy->maximumStorageBytes !== null
            && $projectedStorageBytes > $policy->maximumStorageBytes) {
            foreach (array_reverse($logicalBackups) as $row) {
                $metadata = $row['metadata'];
                $id = $metadata->managedFileId;

                if ($projectedStorageBytes <= $policy->maximumStorageBytes) {
                    break;
                }

                if (! isset($keepReasons[$id]) || isset($keepReasons[$id]['newest'])) {
                    continue;
                }

                unset($keepReasons[$id], $keepBuckets[$id]);
                $storageLimitCandidates[$id] = true;
                $projectedStorageBytes = max(0, $projectedStorageBytes - $metadata->sizeBytes);
            }
        }

        $keep = [];
        $candidateRows = [];

        foreach ($logicalBackups as $row) {
            $metadata = $row['metadata'];
            $id = $metadata->managedFileId;

            if (isset($keepReasons[$id])) {
                $reasons = [];
                $buckets = [];

                foreach (['newest', 'daily', 'weekly', 'monthly'] as $reason) {
                    if (isset($keepReasons[$id][$reason])) {
                        $reasons[] = $reason;
                    }
                    if (isset($keepBuckets[$id][$reason])) {
                        $buckets[$reason] = $keepBuckets[$id][$reason];
                    }
                }

                $keep[] = [
                    ...$metadata->toPlanMetadata(),
                    'reasons' => $reasons,
                    'buckets' => $buckets,
                ];

                continue;
            }

            $candidateRows[] = [
                'metadata' => $metadata,
                'decision' => [
                    ...$metadata->toPlanMetadata(),
                    'reason_code' => isset($storageLimitCandidates[$id])
                        ? 'maximum_storage_bytes'
                        : 'outside_retention_buckets',
                    'duplicate_of_managed_file_id' => null,
                ],
            ];
        }

        foreach ($redundantFiles as $row) {
            $candidateRows[] = [
                'metadata' => $row['metadata'],
                'decision' => [
                    ...$row['metadata']->toPlanMetadata(),
                    'reason_code' => 'redundant_backup_record_duplicate',
                    'duplicate_of_managed_file_id' => $row['duplicate_of'],
                ],
            ];
        }

        usort(
            $candidateRows,
            static fn (array $left, array $right): int => -VerifiedBackupMetadata::compareNewest(
                $left['metadata'],
                $right['metadata'],
            ),
        );
        $deletionCandidates = array_map(
            static fn (array $row): array => $row['decision'],
            $candidateRows,
        );
        usort(
            $protected,
            static fn (array $left, array $right): int => $left['input_index'] <=> $right['input_index'],
        );

        $summary = [
            'input_count' => $inputCount,
            'logical_backup_count' => count($logicalBackups),
            'eligible_unique_file_count' => count($logicalBackups) + count($redundantFiles),
            'collapsed_duplicate_input_count' => $collapsedDuplicateInputs,
            'keep_count' => count($keep),
            'deletion_candidate_count' => count($deletionCandidates),
            'protected_input_count' => count($protected),
            'protected_storage_bytes' => $protectedStorageBytes,
            'current_storage_bytes' => $currentStorageBytes,
            'projected_storage_bytes' => $projectedStorageBytes,
            'maximum_storage_bytes' => $policy->maximumStorageBytes,
            'maximum_storage_satisfied' => $policy->maximumStorageBytes === null
                || $projectedStorageBytes <= $policy->maximumStorageBytes,
        ];
        $planPayload = [
            'policy' => $policy->toArray(),
            'summary' => $summary,
            'keep' => $keep,
            'deletion_candidates' => $deletionCandidates,
            'protected' => $protected,
        ];

        try {
            $planJson = json_encode(
                $planPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new BackupArchiveException('The backup retention plan could not be fingerprinted.');
        }

        return new BackupRetentionPlan(
            policy: $policy,
            keep: $keep,
            deletionCandidates: $deletionCandidates,
            protected: $protected,
            summary: $summary,
            planSha256: hash('sha256', $planJson),
        );
    }

    private function addBytes(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }
}

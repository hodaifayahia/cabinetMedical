<?php

namespace App\Support;

/**
 * Outcome of issuing codes for a selection of cabinets. Like the single-code
 * result, the plaintext lives only in memory here; nothing in this object is
 * ever persisted.
 */
final readonly class BulkIssuedLicenseCodes
{
    /**
     * @param  list<IssuedHostedLicenseCode>  $issued
     * @param  list<SkippedLicenseCode>  $skipped
     */
    public function __construct(
        public array $issued,
        public array $skipped,
    ) {}

    public function issuedCount(): int
    {
        return count($this->issued);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    /** @return list<string> */
    public function skippedCabinetNames(): array
    {
        return array_map(
            static fn (SkippedLicenseCode $skipped): string => $skipped->cabinet->name,
            $this->skipped,
        );
    }
}

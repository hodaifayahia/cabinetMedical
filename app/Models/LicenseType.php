<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'duration_days', 'is_active'])]
class LicenseType extends Model
{
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function expiresAt(CarbonImmutable $startsAt): ?CarbonImmutable
    {
        return $this->duration_days === null ? null : $startsAt->addDays($this->duration_days);
    }

    /** @return HasMany<License, $this> */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /** @return HasMany<HostedLicenseGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(HostedLicenseGrant::class);
    }
}

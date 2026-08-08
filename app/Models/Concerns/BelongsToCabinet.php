<?php

namespace App\Models\Concerns;

use App\Models\Cabinet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restricts tenant-owned models to the authenticated user's cabinet and
 * automatically assigns that cabinet when new records are created.
 *
 * @mixin Model
 */
trait BelongsToCabinet
{
    public static function bootBelongsToCabinet(): void
    {
        static::addGlobalScope('cabinet', static function (Builder $builder): void {
            $user = auth()->user();

            if ($user?->is_platform_admin === true) {
                return;
            }

            $cabinetId = $user?->cabinet_id;

            if ($cabinetId !== null) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('cabinet_id'),
                    $cabinetId,
                );
            }
        });

        static::creating(static function (Model $model): void {
            $user = auth()->user();

            if ($user?->is_platform_admin === true) {
                return;
            }

            if ($model->getAttribute('cabinet_id') === null) {
                $cabinetId = $user?->cabinet_id;

                if ($cabinetId !== null) {
                    $model->setAttribute('cabinet_id', $cabinetId);
                }
            }
        });
    }

    /**
     * @return BelongsTo<Cabinet, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * Explicit escape hatch for platform-level and maintenance queries.
     *
     * @return Builder<static>
     */
    public static function withoutCabinetScope(): Builder
    {
        return static::query()->withoutGlobalScope('cabinet');
    }
}

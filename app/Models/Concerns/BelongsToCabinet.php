<?php

namespace App\Models\Concerns;

use App\Models\Cabinet;
use Illuminate\Auth\Access\AuthorizationException;
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

            $cabinetId = $user?->cabinet_id;

            // An authenticated cabinet member can never create a row for a
            // different tenant, even if a caller supplied cabinet_id
            // explicitly. Console jobs and legacy unscoped accounts retain
            // their existing explicit-assignment behaviour.
            if ($cabinetId !== null) {
                $model->setAttribute('cabinet_id', $cabinetId);
            }
        });

        static::updating(static function (Model $model): void {
            $user = auth()->user();

            if ($user?->is_platform_admin === true || $user?->cabinet_id === null) {
                return;
            }

            $cabinetId = (int) $user->cabinet_id;
            $originalCabinetId = $model->getRawOriginal('cabinet_id');

            if ((int) $originalCabinetId !== $cabinetId) {
                throw new AuthorizationException(
                    'Ce dossier appartient à un autre cabinet.',
                );
            }

            // Cabinet membership is immutable through tenant-facing writes.
            $model->setAttribute('cabinet_id', $cabinetId);
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

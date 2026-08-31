<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Cabinets\CabinetResource;
use App\Models\Cabinet;
use App\Models\License;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The commercial follow-up list: every licence already expired or expiring
 * inside the renewal window, soonest first.
 */
class LicenceExpiryRadar extends TableWidget
{
    private const HORIZON_DAYS = 30;

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Cabinet::query()
                    ->with(['owner:id,name,email', 'license'])
                    ->whereHas('license', fn (Builder $license): Builder => $license
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now()->addDays(self::HORIZON_DAYS))
                        ->where('status', '!=', 'revoked'))
                    // Ordered through a correlated subquery rather than a
                    // join: several columns (id, status, created_at) exist on
                    // both tables and would become ambiguous under search.
                    ->orderBy(
                        License::query()
                            ->select('expires_at')
                            ->whereColumn('licenses.id', 'cabinets.license_id'),
                    ),
            )
            ->heading('Licences à renouveler')
            ->description('Expirées ou arrivant à échéance sous '.self::HORIZON_DAYS.' jours.')
            ->headerActions([
                Action::make('openCabinets')
                    ->label('Ouvrir les cabinets')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(CabinetResource::getUrl('index')),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Cabinet')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Cabinet $record): ?string => $record->owner?->email)
                    ->searchable(),
                TextColumn::make('license.type')
                    ->label('Licence')
                    ->state(fn (Cabinet $record): string => $record->license?->typeLabel() ?? '—')
                    ->badge()
                    ->color('info'),
                TextColumn::make('license.expires_at')
                    ->label('Échéance')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('countdown')
                    ->label('Reste')
                    ->state(fn (Cabinet $record): string => self::countdownLabel($record))
                    ->badge()
                    ->color(fn (Cabinet $record): string => self::isExpired($record) ? 'danger' : 'warning'),
                TextColumn::make('license.status')
                    ->label('État')
                    ->state(fn (Cabinet $record): string => $record->license?->effectiveStatusLabel() ?? '—')
                    ->badge()
                    ->color(fn (Cabinet $record): string => match ($record->license?->effectiveStatus()) {
                        'active' => 'success',
                        'expired', 'revoked' => 'danger',
                        'suspended' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordActions([
                Action::make('renew')
                    ->label('Renouveler')
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (Cabinet $record): string => CabinetResource::getUrl('index', [
                        'tableSearch' => $record->name,
                    ])),
            ])
            ->emptyStateHeading('Aucune échéance proche')
            ->emptyStateDescription('Aucune licence n’expire dans les '.self::HORIZON_DAYS.' prochains jours.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    private static function isExpired(Cabinet $cabinet): bool
    {
        return $cabinet->license?->isExpired() === true;
    }

    private static function countdownLabel(Cabinet $cabinet): string
    {
        $expiresAt = $cabinet->license?->expires_at;

        if ($expiresAt === null) {
            return '—';
        }

        return self::isExpired($cabinet)
            ? 'Expirée '.$expiresAt->diffForHumans(short: true)
            : $expiresAt->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }
}

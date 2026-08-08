<?php

namespace App\Filament\Widgets;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Resources\Cabinets\CabinetResource;
use App\Filament\Resources\DesktopDownloadLeads\DesktopDownloadLeadResource;
use App\Models\Cabinet;
use App\Models\DesktopDownloadLead;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class AdminOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 3,
    ];

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getHeading(): ?string
    {
        return 'Vue d’ensemble de la plateforme';
    }

    protected function getDescription(): ?string
    {
        return 'Suivi en temps réel des inscriptions et des licences Drclick.';
    }

    protected function getStats(): array
    {
        $pending = Cabinet::query()
            ->where('status', CabinetStatus::PENDING->value)
            ->count();
        $active = Cabinet::query()
            ->where('status', CabinetStatus::ACTIVE->value)
            ->where(function (Builder $query): void {
                $query->whereNull('license_id')
                    ->orWhereHas('license', fn (Builder $license): Builder => $license
                        ->where('status', 'active')
                        ->where(function (Builder $validity): void {
                            $validity->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        }));
            })
            ->count();
        $suspended = Cabinet::query()
            ->where('status', CabinetStatus::SUSPENDED->value)
            ->count();
        $trials = Cabinet::query()
            ->whereHas('license', fn (Builder $query): Builder => $query
                ->where('plan', LicensePlan::TRIAL->value)
                ->where('status', 'active')
                ->where('expires_at', '>', now()))
            ->count();
        $lifetime = Cabinet::query()
            ->whereHas('license', fn (Builder $query): Builder => $query
                ->where('plan', LicensePlan::LIFETIME->value))
            ->count();
        $expired = Cabinet::query()
            ->whereHas('license', fn (Builder $query): Builder => $query
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()))
            ->count();
        $desktopLeads = DesktopDownloadLead::query()->count();
        $desktopLeadsThisWeek = DesktopDownloadLead::query()
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        $cabinetsUrl = CabinetResource::getUrl('index');
        $desktopLeadsUrl = DesktopDownloadLeadResource::getUrl('index');

        return [
            Stat::make('En attente', (string) $pending)
                ->description('Cabinets à activer')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url($cabinetsUrl),
            Stat::make('Accès autorisés', (string) $active)
                ->description('Accès autorisé')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url($cabinetsUrl),
            Stat::make('Suspendus', (string) $suspended)
                ->description('Accès interrompu')
                ->descriptionIcon(Heroicon::OutlinedNoSymbol)
                ->color($suspended > 0 ? 'danger' : 'gray')
                ->url($cabinetsUrl),
            Stat::make('Essais en cours', (string) $trials)
                ->description('Licences de 7 jours actives')
                ->descriptionIcon(Heroicon::OutlinedSparkles)
                ->color('info')
                ->url($cabinetsUrl),
            Stat::make('Licences à vie', (string) $lifetime)
                ->description('Accès sans expiration')
                ->descriptionIcon(Heroicon::OutlinedKey)
                ->color('primary')
                ->url($cabinetsUrl),
            Stat::make('Licences expirées', (string) $expired)
                ->description('Renouvellement nécessaire')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($expired > 0 ? 'danger' : 'gray')
                ->url($cabinetsUrl),
            Stat::make('Demandes desktop', (string) $desktopLeads)
                ->description("{$desktopLeadsThisWeek} cette semaine")
                ->descriptionIcon(Heroicon::OutlinedArrowDownTray)
                ->color('info')
                ->url($desktopLeadsUrl),
        ];
    }
}

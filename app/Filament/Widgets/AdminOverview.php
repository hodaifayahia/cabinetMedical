<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Patient;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(PermissionName::CONFIGURATION_LICENSING_MANAGE->value);
    }

    public function getHeading(): ?string
    {
        return 'Vue d’ensemble';
    }

    protected function getStats(): array
    {
        $activeLicenses = License::query()->where('status', 'active')->count();
        $expiringSoon = License::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->count();

        $signInsToday = AuditLog::query()
            ->where('action', 'auth.login')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return [
            Stat::make('Patients', (string) Patient::query()->count())
                ->description('Dossiers patients')
                ->descriptionIcon(Heroicon::OutlinedIdentification)
                ->color('primary'),
            Stat::make('Personnel', (string) User::query()->count())
                ->description('Comptes utilisateurs')
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('info'),
            Stat::make('Licences actives', (string) $activeLicenses)
                ->description($expiringSoon > 0 ? "{$expiringSoon} expirent sous 30 jours" : 'Aucune expiration proche')
                ->descriptionIcon(Heroicon::OutlinedKey)
                ->color($expiringSoon > 0 ? 'warning' : 'success'),
            Stat::make('Appareils', (string) Device::query()->count())
                ->description('Installations enregistrées')
                ->descriptionIcon(Heroicon::OutlinedComputerDesktop)
                ->color('gray'),
            Stat::make('Connexions aujourd’hui', (string) $signInsToday)
                ->description('Sessions ouvertes')
                ->descriptionIcon(Heroicon::OutlinedFingerPrint)
                ->color('success'),
        ];
    }
}

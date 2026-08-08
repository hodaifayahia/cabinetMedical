<?php

namespace App\Filament\Resources\DesktopDownloadLeads;

use App\Filament\Resources\DesktopDownloadLeads\Pages\ListDesktopDownloadLeads;
use App\Filament\Resources\DesktopDownloadLeads\Tables\DesktopDownloadLeadsTable;
use App\Models\DesktopDownloadLead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DesktopDownloadLeadResource extends Resource
{
    protected static ?string $model = DesktopDownloadLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Plateforme';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'demande desktop';
    }

    public static function getPluralModelLabel(): string
    {
        return 'demandes desktop';
    }

    public static function getNavigationLabel(): string
    {
        return 'Téléchargements desktop';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $notDownloaded = DesktopDownloadLead::query()->whereNull('downloaded_at')->count();

        return $notDownloaded > 0 ? (string) $notDownloaded : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function table(Table $table): Table
    {
        return DesktopDownloadLeadsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesktopDownloadLeads::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ActivationKeys;

use App\Filament\Resources\ActivationKeys\Pages\ListActivationKeys;
use App\Filament\Resources\ActivationKeys\Tables\ActivationKeysTable;
use App\Models\HostedLicenseGrant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every activation code generated for a cabinet, whatever the screen it was
 * generated from. Codes used to exist only in the e-mail that carried them,
 * which left the operator with no list to work from.
 */
class ActivationKeyResource extends Resource
{
    protected static ?string $model = HostedLicenseGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & activations';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return 'clé d’activation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'clés d’activation';
    }

    public static function getNavigationLabel(): string
    {
        return 'Clés d’activation';
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

    /**
     * Codes waiting to be entered by a customer are the operator's live
     * queue, so the badge counts exactly those.
     */
    public static function getNavigationBadge(): ?string
    {
        $outstanding = HostedLicenseGrant::withoutCabinetScope()->outstanding()->count();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Builder<HostedLicenseGrant> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<HostedLicenseGrant> $query */
        $query = parent::getEloquentQuery()
            ->withoutGlobalScope('cabinet')
            ->with(['cabinet.owner', 'licenseType', 'issuer', 'redeemer']);

        return $query;
    }

    public static function table(Table $table): Table
    {
        return ActivationKeysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivationKeys::route('/'),
        ];
    }
}

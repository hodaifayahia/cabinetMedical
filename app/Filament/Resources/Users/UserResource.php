<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Accounts that can sign in to this back office. Cabinet staff are tenant
 * identities living in each cabinet's own local database and are managed
 * from the cabinet's seat-aware staff flow, never from here.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'compte plateforme';
    }

    public static function getPluralModelLabel(): string
    {
        return 'comptes plateforme';
    }

    public static function getNavigationLabel(): string
    {
        return 'Utilisateurs';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    /**
     * Only platform accounts. A cabinet's users are stored in that cabinet's
     * local installation, so listing them here was showing rows this panel
     * can neither reach nor administer.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_platform_admin', true);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    /**
     * Two ways to lock everyone out are blocked here: deleting your own
     * account mid-session, and deleting the last account that can sign in.
     */
    public static function canDelete(Model $record): bool
    {
        if (auth()->user()?->is_platform_admin !== true) {
            return false;
        }

        if ($record->getKey() === auth()->id()) {
            return false;
        }

        return static::getEloquentQuery()->count() > 1;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}

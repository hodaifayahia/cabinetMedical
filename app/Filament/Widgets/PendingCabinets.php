<?php

namespace App\Filament\Widgets;

use App\Enums\CabinetStatus;
use App\Filament\Resources\Cabinets\CabinetResource;
use App\Models\Cabinet;
use App\Support\Wilayas;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingCabinets extends TableWidget
{
    protected static ?int $sort = -2;

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
                    ->with([
                        'owner:id,name,email',
                        'settings:id,cabinet_id,phone',
                    ])
                    ->where('status', CabinetStatus::PENDING->value)
                    ->limit(8),
            )
            ->heading('Cabinets en attente d’activation')
            ->description('Les inscriptions les plus récentes à vérifier et à licencier.')
            ->headerActions([
                Action::make('manageCabinets')
                    ->label('Gérer tous les cabinets')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(CabinetResource::getUrl('index')),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Cabinet')
                    ->weight(FontWeight::SemiBold)
                    ->searchable(),
                TextColumn::make('owner.name')
                    ->label('Propriétaire')
                    ->placeholder('Non renseigné'),
                TextColumn::make('owner.email')
                    ->label('E-mail')
                    ->copyable()
                    ->placeholder('Non renseigné'),
                TextColumn::make('settings.phone')
                    ->label('Téléphone')
                    ->copyable()
                    ->placeholder('Non renseigné'),
                TextColumn::make('specialization')
                    ->label('Spécialité')
                    ->placeholder('Non renseignée'),
                TextColumn::make('wilaya_code')
                    ->label('Wilaya')
                    ->formatStateUsing(fn (?int $state): string => Wilayas::label($state) ?? 'Non renseignée'),
                TextColumn::make('created_at')
                    ->label('Inscrit')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->recordActions([
                Action::make('licenseCabinet')
                    ->label('Attribuer une licence')
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (Cabinet $record): string => CabinetResource::getUrl('index', [
                        'tableSearch' => $record->name,
                    ])),
            ])
            ->emptyStateHeading('Aucun cabinet en attente')
            ->emptyStateDescription('Toutes les inscriptions ont été traitées.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }
}

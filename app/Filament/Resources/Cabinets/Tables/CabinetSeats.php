<?php

namespace App\Filament\Resources\Cabinets\Tables;

use App\Models\Cabinet;
use App\Services\CabinetFulfillmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/**
 * The cabinet's seat allowance in the admin panel: how many accounts each
 * doctor may hold and the price agreed for them. The column expects the table
 * query to load `users_count`.
 */
final class CabinetSeats
{
    public static function column(): TextColumn
    {
        return TextColumn::make('seat_limit')
            ->label('Sièges')
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (Cabinet $record): string => self::usedSeats($record).' / '.$record->seatLimit())
            ->color(fn (Cabinet $record): string => self::usedSeats($record) >= $record->seatLimit() ? 'warning' : 'success')
            ->description(fn (Cabinet $record): ?string => self::priceLabel($record->seat_price));
    }

    /**
     * The fields shown when a cabinet is activated and when its seats are
     * changed later, pre-filled with what the cabinet has today.
     *
     * @return list<TextInput>
     */
    public static function fields(bool $required = true): array
    {
        $limit = TextInput::make('seat_limit')
            ->label('Nombre de sièges')
            ->helperText('Comptes autorisés, médecin inclus. '.Cabinet::DEFAULT_SEATS.' = le médecin + 1 utilisateur.')
            ->numeric()
            ->integer()
            ->minValue(1)
            ->maxValue(Cabinet::MAX_GRANTABLE_SEATS)
            ->suffix('sièges');

        $price = TextInput::make('seat_price')
            ->label('Prix par siège')
            ->helperText('Tarif convenu avec ce cabinet. Laissez vide si aucun.')
            ->numeric()
            ->integer()
            ->minValue(0)
            ->maxValue(100_000_000)
            ->suffix('DA');

        if (! $required) {
            return [
                $limit->placeholder('Inchangé')
                    ->helperText('Appliqué à chaque cabinet sélectionné. Laissez vide pour conserver les sièges actuels.'),
                $price->placeholder('Inchangé')
                    ->helperText('Laissez vide pour conserver le tarif actuel.'),
            ];
        }

        return [
            $limit->required()->default(fn (?Cabinet $record): int => $record?->seatLimit() ?? Cabinet::DEFAULT_SEATS),
            $price->default(fn (?Cabinet $record): ?int => $record?->seat_price),
        ];
    }

    public static function manageAction(): Action
    {
        return Action::make('manageSeats')
            ->label('Sièges et tarif')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('primary')
            ->modalHeading(fn (Cabinet $record): string => 'Sièges — '.$record->name)
            ->modalDescription(fn (Cabinet $record): string => 'Utilisés actuellement : '.$record->seatsInUse().' / '.$record->seatLimit()
                .' (demandes en attente comprises). Réduire la limite ne supprime aucun compte ; le cabinet ne pourra simplement plus en ajouter. '
                .'Un poste hors ligne reçoit la nouvelle limite dès qu’il se connecte à Internet.')
            ->modalSubmitActionLabel('Enregistrer')
            ->schema(self::fields())
            ->action(function (Cabinet $record, array $data): void {
                $cabinet = self::apply($record, $data);

                Notification::make()
                    ->title('Sièges mis à jour')
                    ->body($cabinet->name.' : '.$cabinet->seatLimit().' sièges'
                        .(($price = self::priceLabel($cabinet->seat_price)) !== null ? ' · '.$price : '').'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Save the seat fields of an admin form. A blank price clears it, except
     * in the bulk form ($keepBlank), where blank means "leave as it is".
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(Cabinet $cabinet, array $data, bool $keepBlank = false): Cabinet
    {
        $limit = $data['seat_limit'] ?? null;
        $price = $data['seat_price'] ?? null;

        return app(CabinetFulfillmentService::class)->updateSeatAllowance(
            $cabinet,
            filled($limit) ? (int) $limit : $cabinet->seatLimit(),
            filled($price) ? (int) $price : ($keepBlank ? $cabinet->seat_price : null),
        );
    }

    public static function priceLabel(?int $price): ?string
    {
        return $price === null ? null : number_format($price, 0, ',', ' ').' DA / siège';
    }

    private static function usedSeats(Cabinet $record): int
    {
        return (int) ($record->users_count ?? $record->seatsInUse());
    }
}

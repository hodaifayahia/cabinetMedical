<?php

namespace App\Filament\Resources\MedicalSpecialties;

use App\Filament\Resources\MedicalSpecialties\Pages\CreateMedicalSpecialty;
use App\Filament\Resources\MedicalSpecialties\Pages\EditMedicalSpecialty;
use App\Filament\Resources\MedicalSpecialties\Pages\ListMedicalSpecialties;
use App\Models\AuditLog;
use App\Models\MedicalSpecialty;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyDoctorCounts;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The medical specialty catalogue, as the platform admin manages it.
 *
 * "Proposée aux patients" decides whether the patient app offers the specialty
 * as a search filter. Switching it off never hides a doctor. The code is
 * derived from the French label at creation and never edited afterwards:
 * doctor profiles store it. There is no delete — deactivate instead.
 */
class MedicalSpecialtyResource extends Resource
{
    protected static ?string $model = MedicalSpecialty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Clients';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Spécialités';
    }

    public static function getModelLabel(): string
    {
        return 'spécialité';
    }

    public static function getPluralModelLabel(): string
    {
        return 'spécialités';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label_fr')->label('Nom (français)')->required()->minLength(2)->maxLength(100),
            TextInput::make('label_ar')->label('Nom (arabe)')->required()->minLength(2)->maxLength(100)
                ->extraInputAttributes(['dir' => 'rtl']),
            Toggle::make('is_active')->label('Proposée aux patients')->default(true)
                ->helperText('Désactivée, elle disparaît du filtre de recherche de l’application patient. Les médecins de cette spécialité restent visibles.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label_fr')->label('Spécialité')->searchable()->sortable()->weight('bold'),
                TextColumn::make('label_ar')->label('Arabe')->searchable(),
                TextColumn::make('doctors_count')->label('Médecins visibles')
                    // One count query per page render, shared by every row.
                    ->state(static fn (MedicalSpecialty $record): int => once(
                        static fn (): array => app(SpecialtyDoctorCounts::class)->listed(),
                    )[$record->code] ?? 0)
                    ->badge()->color(static fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                ToggleColumn::make('is_active')->label('Proposée aux patients')
                    ->afterStateUpdated(static function (MedicalSpecialty $record, bool $state): void {
                        AuditLog::record('admin.specialty_updated', $record, [
                            'code' => $record->code,
                            'is_active' => $state,
                        ], auth()->id());
                    }),
                TextColumn::make('code')->label('Code')->color('gray')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Proposée aux patients'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedicalSpecialties::route('/'),
            'create' => CreateMedicalSpecialty::route('/create'),
            'edit' => EditMedicalSpecialty::route('/{record}/edit'),
        ];
    }

    /**
     * Normalise the form data and refuse a French name the catalogue already
     * has (case-insensitive, compared in PHP: SQLite's lower() only folds
     * ASCII). Returns the cleaned data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareLabels(array $data, ?MedicalSpecialty $record = null): array
    {
        $data['label_fr'] = Str::squish((string) $data['label_fr']);
        $data['label_ar'] = Str::squish((string) $data['label_ar']);

        $needle = Str::lower($data['label_fr']);
        $taken = MedicalSpecialty::query()
            ->when($record !== null, static fn (Builder $query) => $query->whereKeyNot($record->getKey()))
            ->pluck('label_fr')
            ->contains(static fn (string $label): bool => Str::lower($label) === $needle);

        if ($taken) {
            throw ValidationException::withMessages(['data.label_fr' => 'Une spécialité porte déjà ce nom.']);
        }

        return $data;
    }

    /**
     * Code for a new specialty: the catalogue's own derivation, so a label it
     * already resolves (a renamed built-in, a legacy spelling) is refused.
     */
    public static function newCode(string $labelFr): string
    {
        $code = app(MedicalSpecialtyCatalog::class)->codeFor($labelFr);

        if (MedicalSpecialty::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['data.label_fr' => 'Cette spécialité existe déjà.']);
        }

        return $code;
    }
}

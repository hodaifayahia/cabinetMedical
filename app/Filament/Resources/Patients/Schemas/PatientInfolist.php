<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('patient_number'),
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
                TextEntry::make('date_of_birth')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('gender')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('phone')
                    ->placeholder('-'),
                TextEntry::make('secondary_phone')
                    ->placeholder('-'),
                TextEntry::make('email')
                    ->label('Email address')
                    ->placeholder('-'),
                TextEntry::make('address')
                    ->placeholder('-'),
                TextEntry::make('city')
                    ->placeholder('-'),
                TextEntry::make('emergency_contact_name')
                    ->placeholder('-'),
                TextEntry::make('emergency_contact_phone')
                    ->placeholder('-'),
                TextEntry::make('blood_group')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('notes')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (Patient $record): bool => $record->trashed()),
                TextEntry::make('marital_status')
                    ->placeholder('-'),
                TextEntry::make('profession')
                    ->placeholder('-'),
                TextEntry::make('smoking_status')
                    ->placeholder('-'),
                TextEntry::make('referred_by')
                    ->placeholder('-'),
                TextEntry::make('allergies')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('antecedents_medical')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('antecedents_surgical')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('antecedents_family')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('antecedents_gyneco')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('antecedents_other')
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }
}

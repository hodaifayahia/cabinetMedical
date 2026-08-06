<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('patient_number')
                    ->required(),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name')
                    ->required(),
                DatePicker::make('date_of_birth'),
                Select::make('gender')
                    ->options(Gender::class),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('secondary_phone')
                    ->tel(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('address'),
                TextInput::make('city'),
                TextInput::make('emergency_contact_name'),
                TextInput::make('emergency_contact_phone')
                    ->tel(),
                Select::make('blood_group')
                    ->options(BloodGroup::class),
                Textarea::make('notes')
                    ->columnSpanFull(),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('marital_status'),
                TextInput::make('profession'),
                TextInput::make('smoking_status'),
                TextInput::make('referred_by'),
                Textarea::make('allergies')
                    ->columnSpanFull(),
                Textarea::make('antecedents_medical')
                    ->columnSpanFull(),
                Textarea::make('antecedents_surgical')
                    ->columnSpanFull(),
                Textarea::make('antecedents_family')
                    ->columnSpanFull(),
                Textarea::make('antecedents_gyneco')
                    ->columnSpanFull(),
                Textarea::make('antecedents_other')
                    ->columnSpanFull(),
            ]);
    }
}

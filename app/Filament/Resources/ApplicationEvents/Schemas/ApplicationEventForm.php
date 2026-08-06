<?php

namespace App\Filament\Resources\ApplicationEvents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ApplicationEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('event')
                    ->label('Événement'),
                TextInput::make('severity')
                    ->label('Gravité'),
                Textarea::make('message')
                    ->label('Message')
                    ->columnSpanFull(),
                DateTimePicker::make('occurred_at')
                    ->label('Survenu le'),
            ]);
    }
}

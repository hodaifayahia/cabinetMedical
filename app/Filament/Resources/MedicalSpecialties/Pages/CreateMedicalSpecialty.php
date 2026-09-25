<?php

namespace App\Filament\Resources\MedicalSpecialties\Pages;

use App\Filament\Resources\MedicalSpecialties\MedicalSpecialtyResource;
use App\Models\AuditLog;
use App\Models\MedicalSpecialty;
use Filament\Resources\Pages\CreateRecord;

class CreateMedicalSpecialty extends CreateRecord
{
    protected static string $resource = MedicalSpecialtyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = MedicalSpecialtyResource::prepareLabels($data);
        $data['code'] = MedicalSpecialtyResource::newCode($data['label_fr']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var MedicalSpecialty $record */
        $record = $this->record;

        AuditLog::record('admin.specialty_created', $record, [
            'code' => $record->code,
            'label_fr' => $record->label_fr,
            'is_active' => $record->is_active,
        ], auth()->id());
    }
}

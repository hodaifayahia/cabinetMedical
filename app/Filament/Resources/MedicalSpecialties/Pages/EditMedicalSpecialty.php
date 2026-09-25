<?php

namespace App\Filament\Resources\MedicalSpecialties\Pages;

use App\Filament\Resources\MedicalSpecialties\MedicalSpecialtyResource;
use App\Models\AuditLog;
use App\Models\MedicalSpecialty;
use Filament\Resources\Pages\EditRecord;

/** No delete action: doctor profiles store the code, so deactivate instead. */
class EditMedicalSpecialty extends EditRecord
{
    protected static string $resource = MedicalSpecialtyResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var MedicalSpecialty $record */
        $record = $this->record;

        return MedicalSpecialtyResource::prepareLabels($data, $record);
    }

    protected function afterSave(): void
    {
        /** @var MedicalSpecialty $record */
        $record = $this->record;

        AuditLog::record('admin.specialty_updated', $record, [
            'code' => $record->code,
            'label_fr' => $record->label_fr,
            'label_ar' => $record->label_ar,
            'is_active' => $record->is_active,
        ], auth()->id());
    }
}

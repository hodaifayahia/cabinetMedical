<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_numbers_are_generated_automatically(): void
    {
        $patient = Patient::factory()->create();

        $this->assertMatchesRegularExpression('/^PAT-\d{8}-[A-Z0-9]{6}$/', $patient->patient_number);
    }

    public function test_generated_patient_numbers_are_unique(): void
    {
        $patients = Patient::factory()->count(2)->create();

        $this->assertNotSame($patients[0]->patient_number, $patients[1]->patient_number);
    }
}

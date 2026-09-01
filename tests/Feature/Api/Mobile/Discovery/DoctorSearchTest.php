<?php

namespace Tests\Feature\Api\Mobile\Discovery;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\Baladiya;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Models\Wilaya;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public doctor directory: only listed, active clinics with an active doctor
 * are ever visible, and the clinic detail page exposes the weekly working
 * hours with morning/evening ranges.
 */
class DoctorSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_only_listed_active_clinics_with_an_active_doctor_appear(): void
    {
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);

        [, $visibleDoctor] = $this->makeSearchableClinic();
        $this->makeSearchableClinic(profile: ['is_listed' => false]);
        $this->makeSearchableClinic(cabinet: ['status' => CabinetStatus::SUSPENDED]);
        $this->makeSearchableClinic(doctor: ['is_active' => false]);

        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleDoctor->getKey())
            ->assertJsonPath('data.0.clinic.wilaya.code', 16)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'specialty', 'clinic' => ['id', 'name', 'wilaya', 'baladiya', 'address']]],
                'links',
                'meta',
            ]);
    }

    public function test_wilaya_baladiya_and_specialty_filters_narrow_the_results(): void
    {
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);
        Wilaya::factory()->create(['code' => 31, 'name_fr' => 'Oran', 'name_ar' => 'وهران']);
        $hydra = Baladiya::factory()->create(['wilaya_code' => 16, 'name_fr' => 'Hydra', 'name_ar' => 'حيدرة']);

        [, $cardiologist] = $this->makeSearchableClinic(
            cabinet: ['wilaya_code' => 16],
            doctor: ['specialty' => 'Cardiology', 'doctor_name' => 'Dr Amine Kaci'],
            profile: ['baladiya_id' => $hydra->getKey()],
        );
        [, $dermatologist] = $this->makeSearchableClinic(
            cabinet: ['wilaya_code' => 31],
            doctor: ['specialty' => 'Dermatology', 'doctor_name' => 'Dr Yacine Brahimi'],
        );

        $this->getJson('/api/v1/doctors?wilaya_code=16')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cardiologist->getKey());

        $this->getJson('/api/v1/doctors?baladiya_id='.$hydra->getKey())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cardiologist->getKey())
            ->assertJsonPath('data.0.clinic.baladiya.name_fr', 'Hydra');

        $this->getJson('/api/v1/doctors?specialty=dermatology')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dermatologist->getKey())
            ->assertJsonPath('data.0.specialty.code', 'dermatology')
            ->assertJsonPath('data.0.specialty.label_fr', 'Dermatologie')
            ->assertJsonPath('data.0.specialty.label_ar', 'الأمراض الجلدية');

        $this->getJson('/api/v1/doctors?wilaya_code=16&specialty=dermatology')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_q_matches_doctor_name_or_clinic_name(): void
    {
        [, $cardiologist] = $this->makeSearchableClinic(
            doctor: ['doctor_name' => 'Dr Amine Kaci'],
        );
        [, $dermatologist] = $this->makeSearchableClinic(
            cabinet: ['name' => 'Cabinet El Chifa'],
            doctor: ['doctor_name' => 'Dr Yacine Brahimi'],
        );

        $this->getJson('/api/v1/doctors?q=Kaci')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cardiologist->getKey());

        $this->getJson('/api/v1/doctors?q=El Chifa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dermatologist->getKey());
    }

    public function test_a_staff_token_sees_the_full_public_directory_not_its_own_cabinet_only(): void
    {
        [, , $doctorUserA] = $this->makeSearchableClinic();
        $this->makeSearchableClinic();

        Sanctum::actingAs($doctorUserA);

        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_clinic_detail_exposes_profile_and_seven_day_working_hours(): void
    {
        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);
        $hydra = Baladiya::factory()->create(['wilaya_code' => 16, 'name_fr' => 'Hydra', 'name_ar' => 'حيدرة']);

        [$cabinet, $doctor] = $this->makeSearchableClinic(
            cabinet: ['wilaya_code' => 16],
            doctor: ['specialty' => 'Cardiology', 'doctor_name' => 'Dr Amine Kaci'],
            profile: [
                'about' => 'Cabinet de cardiologie.',
                'address' => '12 rue Didouche Mourad',
                'baladiya_id' => $hydra->getKey(),
                'phones' => ['0550123456', '0770123456'],
                'latitude' => 36.7538,
                'longitude' => 3.0588,
                'photos' => ['clinics/one.jpg'],
            ],
        );

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'day_of_week' => Weekday::SATURDAY,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'slot_duration' => 30,
        ]);
        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'day_of_week' => Weekday::SATURDAY,
            'starts_at' => '14:00:00',
            'ends_at' => '17:00:00',
            'slot_duration' => 20,
        ]);

        $response = $this->getJson('/api/v1/clinics/'.$cabinet->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $cabinet->getKey())
            ->assertJsonPath('data.about', 'Cabinet de cardiologie.')
            ->assertJsonPath('data.address', '12 rue Didouche Mourad')
            ->assertJsonPath('data.wilaya.code', 16)
            ->assertJsonPath('data.baladiya.name_fr', 'Hydra')
            ->assertJsonPath('data.phones.0', '0550123456')
            ->assertJsonPath('data.photos.0', 'clinics/one.jpg')
            ->assertJsonPath('data.doctor.id', $doctor->getKey())
            ->assertJsonPath('data.doctor.name', 'Dr Amine Kaci')
            ->assertJsonPath('data.doctor.specialty.code', 'cardiology')
            ->assertJsonFragment(['code' => 'cardiology', 'label_fr' => 'Cardiologie', 'label_ar' => 'أمراض القلب'])
            ->assertJsonCount(7, 'data.working_hours');

        // Saturday (ISO weekday 6) carries a morning and an evening range.
        $response->assertJsonPath('data.working_hours.5.weekday', 6)
            ->assertJsonPath('data.working_hours.5.is_closed', false)
            ->assertJsonPath('data.working_hours.5.ranges.0.starts_at', '09:00')
            ->assertJsonPath('data.working_hours.5.ranges.0.period', 'morning')
            ->assertJsonPath('data.working_hours.5.ranges.0.slot_duration', 30)
            ->assertJsonPath('data.working_hours.5.ranges.1.starts_at', '14:00')
            ->assertJsonPath('data.working_hours.5.ranges.1.period', 'evening')
            ->assertJsonPath('data.working_hours.5.ranges.1.slot_duration', 20);

        // Every other weekday is present and closed.
        $response->assertJsonPath('data.working_hours.0.weekday', 1)
            ->assertJsonPath('data.working_hours.0.is_closed', true)
            ->assertJsonPath('data.working_hours.0.ranges', []);
    }

    public function test_clinic_detail_is_404_for_unlisted_inactive_or_unknown_cabinets(): void
    {
        [$unlisted] = $this->makeSearchableClinic(profile: ['is_listed' => false]);
        [$suspended] = $this->makeSearchableClinic(cabinet: ['status' => CabinetStatus::SUSPENDED]);

        $this->getJson('/api/v1/clinics/'.$unlisted->getKey())->assertNotFound();
        $this->getJson('/api/v1/clinics/'.$suspended->getKey())->assertNotFound();
        $this->getJson('/api/v1/clinics/999999')->assertNotFound();
    }

    /**
     * A discoverable clinic with explicit, overridable attributes. Mirrors
     * Tests\Support\MobileTestHelpers::makeListedClinic() but lets each test
     * pin the cabinet, doctor and public-profile columns the filters need.
     *
     * @param  array<string, mixed>  $cabinet
     * @param  array<string, mixed>  $doctor
     * @param  array<string, mixed>  $profile
     * @return array{0: Cabinet, 1: DoctorProfile, 2: User}
     */
    private function makeSearchableClinic(array $cabinet = [], array $doctor = [], array $profile = []): array
    {
        $cabinetModel = Cabinet::query()->create(array_merge([
            'name' => 'Cabinet '.fake()->unique()->lastName(),
            'status' => CabinetStatus::ACTIVE,
            'wilaya_code' => 16,
            'activated_at' => now(),
        ], $cabinet));

        $doctorUser = User::factory()->create([
            'cabinet_id' => $cabinetModel->getKey(),
            'approved_at' => now(),
        ]);
        $doctorUser->assignRole(RoleName::DOCTOR->value);

        $doctorModel = DoctorProfile::factory()
            ->for($doctorUser, 'user')
            ->create(array_merge([
                'cabinet_id' => $cabinetModel->getKey(),
                'is_active' => true,
            ], $doctor));

        CabinetPublicProfile::factory()->listed()->create(array_merge([
            'cabinet_id' => $cabinetModel->getKey(),
        ], $profile));

        return [$cabinetModel, $doctorModel, $doctorUser];
    }
}

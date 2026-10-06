<?php

namespace Tests\Feature\OnlineService;

use App\CabinetTransfer\CabinetTransferCatalog;
use App\CabinetTransfer\OnlineCabinetImporter;
use App\CabinetTransfer\TransferState;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Services\MachineFingerprintService;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SignsCabinetEntitlements;
use Tests\TestCase;

/**
 * A cabinet that used the online service moves to its PC: every record
 * and file is copied with its id, verified, then removed online except
 * what the mobile app needs.
 */
class CabinetTransferToDesktopTest extends TestCase
{
    use RefreshDatabase;
    use SignsCabinetEntitlements;

    private const CLOUD = 'https://cloud.drclick.test';

    private const PC = 'http://127.0.0.1:47800';

    private const OWNER = 'dr.benali@example.com';

    /** @var array<string, array{status: int, body: string}> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpEntitlementKeys();
        Storage::fake('local');
        Storage::fake('public');
        TransferState::clear();
        $this->beforeApplicationDestroyed(static fn () => TransferState::clear());
    }

    public function test_an_online_cabinet_moves_to_an_empty_pc_with_every_record_and_file(): void
    {
        $this->onTheOnlineService();
        [$cabinet, $owner] = $this->onlineCabinet();
        $expected = $this->snapshot($cabinet);
        $this->recordServerAnswers($owner);

        // The same database now plays the fresh PC.
        app(OnlineCabinetImporter::class)->reset();
        Storage::fake('local');
        Storage::fake('public');
        $this->assertSame(0, User::query()->count());
        $this->onTheDesktop();
        $this->fakeServer();

        $this->post(self::PC.'/desktop/cabinet-login', [
            'owner_email' => self::OWNER,
            'email' => self::OWNER,
            'password' => 'mot-de-passe-en-ligne',
            'import_records' => '1',
        ])->assertRedirect(route('desktop.transfer.show'))->assertSessionHasNoErrors();

        // The queue runs synchronously in tests: the copy is already done.
        $status = $this->getJson(self::PC.'/desktop/transfer/status')->assertOk();
        $this->assertSame(TransferState::IMPORTED, $status->json('status'), (string) $status->json('error'));
        $status
            ->assertJsonPath('summary.patients', 3)
            ->assertJsonPath('summary.consultations', 1);

        $this->assertSame($expected, $this->snapshot(Cabinet::query()->sole()));
        $this->assertSame('compte-rendu.pdf contents', Storage::disk('local')->get('documents/compte-rendu.pdf'));

        $imported = Cabinet::query()->sole();
        $this->assertSame(CabinetStatus::ACTIVE, $imported->status);
        $this->assertNotNull($imported->license_id, 'The cabinet is activated with a licence of this PC.');
        $this->assertTrue(app(MobileSyncSettings::class)->isConfigured());

        // The owner signs in with the online password; roles came along.
        $pcOwner = User::query()->where('email', self::OWNER)->sole();
        $this->assertTrue($pcOwner->hasRole(RoleName::ADMINISTRATOR->value));
        $this->assertNull(
            Patient::query()->withoutGlobalScopes()->where('first_name', 'Mobile')->sole()->patient_user_id,
            'The link to the online mobile account is cleared on the PC.',
        );

        // Last step: remove the copy left online.
        $this->post(self::PC.'/desktop/transfer/purge', ['confirmation' => 'SUPPRIMER'])
            ->assertRedirect(route('desktop.transfer.show'));
        $this->getJson(self::PC.'/desktop/transfer/status')->assertJsonPath('status', TransferState::PURGED);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::CLOUD.'/api/v1/cabinet-transfer/complete'
            && $request['installation_id'] === app(MachineFingerprintService::class)->installationId()
            && strlen((string) $request['fingerprint']) === 64);
    }

    public function test_the_online_copy_is_removed_except_what_the_mobile_app_needs(): void
    {
        $this->onTheOnlineService();
        [$cabinet, $owner] = $this->onlineCabinet();
        Sanctum::actingAs($owner);
        $fingerprint = $this->getJson('/api/v1/cabinet-transfer/manifest')->json('fingerprint');

        $this->postJson('/api/v1/cabinet-transfer/complete', [
            'fingerprint' => $fingerprint,
            'installation_id' => 'pc-du-cabinet',
        ])->assertOk();

        $this->assertSame(0, DB::table('consultations')->where('cabinet_id', $cabinet->getKey())->count());
        $this->assertSame(0, DB::table('documents')->where('cabinet_id', $cabinet->getKey())->count());
        $this->assertSame(0, DB::table('payments')->where('cabinet_id', $cabinet->getKey())->count());
        $this->assertFalse(Storage::disk('local')->exists('documents/compte-rendu.pdf'));

        $patient = DB::table('patients')->where('first_name', 'Amina')->sole();
        $this->assertSame('0550 11 22 33', $patient->phone, 'Identity and phone stay for mobile bookings.');
        $this->assertNull($patient->allergies);
        $this->assertNull($patient->antecedents_medical);
        $this->assertSame(1, DB::table('appointments')->where('cabinet_id', $cabinet->getKey())->count());

        $cabinet->refresh();
        $this->assertNotNull($cabinet->clinical_data_transferred_at);
        $this->assertSame('pc-du-cabinet', $cabinet->clinical_data_transferred_to);
    }

    public function test_records_changed_since_the_copy_are_not_removed(): void
    {
        $this->onTheOnlineService();
        [, $owner] = $this->onlineCabinet();
        Sanctum::actingAs($owner);
        $fingerprint = $this->getJson('/api/v1/cabinet-transfer/manifest')->json('fingerprint');

        $this->actingAs($owner);
        Patient::factory()->create(['first_name' => 'Nouveau']);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/cabinet-transfer/complete', [
            'fingerprint' => $fingerprint,
            'installation_id' => 'pc-du-cabinet',
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('consultations')->count());
    }

    public function test_only_the_cabinet_owner_can_take_its_records(): void
    {
        $this->onTheOnlineService();
        [$cabinet] = $this->onlineCabinet();
        $assistant = User::query()->where('cabinet_id', $cabinet->getKey())
            ->where('email', '!=', self::OWNER)->firstOrFail();

        Sanctum::actingAs($assistant);
        $this->getJson('/api/v1/cabinet-transfer/manifest')->assertForbidden();
        $this->getJson('/api/v1/cabinet-transfer/tables/patients')->assertForbidden();
    }

    private function onTheOnlineService(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => false,
            'hub.enabled' => false,
        ]);
    }

    private function onTheDesktop(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            // Given by the desktop shell to its supervised runtime.
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a7b-9c0d1e2f3a4b',
            'medismart.runtime.local_url' => self::PC,
            'medismart.online_service.linkable' => true,
            'medismart.online_service.url' => self::CLOUD,
            'medismart.licensing.entitlement_signing_key_path' => null,
        ]);
    }

    /** @return array{0: Cabinet, 1: User} */
    private function onlineCabinet(): array
    {
        $owner = app(CabinetProvisioningService::class)->provision([
            'name' => 'Dr Amina Benali',
            'email' => self::OWNER,
            'password' => 'mot-de-passe-en-ligne',
            'phone' => '0550000000',
            'cabinet_name' => 'Cabinet Ibn Sina',
            'specialization' => 'Médecine générale',
            'wilaya' => 31,
        ]);
        $cabinet = $owner->cabinet;
        $cabinet->forceFill(['status' => CabinetStatus::ACTIVE, 'activated_at' => now()])->save();

        $assistant = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
            'email' => 'assistante@example.com',
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        // A patient who booked from the mobile app has an online account.
        $mobileAccount = User::factory()->create(['cabinet_id' => null, 'email' => 'patient.mobile@example.com']);

        $this->actingAs($owner);
        $amina = Patient::factory()->create([
            'first_name' => 'Amina',
            'phone' => '0550 11 22 33',
            'allergies' => 'Pénicilline',
            'antecedents_medical' => 'Asthme',
        ]);
        Patient::factory()->create(['first_name' => 'Karim']);
        Patient::factory()->create(['first_name' => 'Mobile', 'patient_user_id' => $mobileAccount->getKey()]);

        Appointment::query()->create([
            'patient_id' => $amina->getKey(),
            'appointment_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(9, 30),
            'status' => 'scheduled',
            'public_id' => (string) Str::uuid(),
        ]);
        $consultation = Consultation::query()->create([
            'patient_id' => $amina->getKey(),
            'consulted_at' => now(),
            'status' => 'completed',
            'payment_amount_minor' => 200000,
            'payment_service' => 'Consultation',
            'is_paid' => true,
            'created_by' => $owner->getKey(),
        ]);
        $consultation->payments()->create([
            'patient_id' => $amina->getKey(),
            'amount_minor' => 200000,
            'method' => 'Cash',
            'received_at' => now(),
        ]);

        Storage::disk('local')->put('documents/compte-rendu.pdf', 'compte-rendu.pdf contents');
        Document::query()->create([
            'patient_id' => $amina->getKey(),
            'consultation_id' => $consultation->getKey(),
            'category' => 'report',
            'title' => 'Compte rendu',
            'paper_size' => 'A4',
            'file_path' => 'documents/compte-rendu.pdf',
            'original_filename' => 'compte-rendu.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 25,
        ]);

        auth()->logout();

        return [$cabinet, $owner];
    }

    /** @return array<string, mixed> */
    private function snapshot(Cabinet $cabinet): array
    {
        $rows = static fn (string $table, array $columns): array => DB::table($table)
            ->where('cabinet_id', $cabinet->getKey())
            ->orderBy('id')
            ->get(['id', ...$columns])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'cabinet' => [$cabinet->getKey(), $cabinet->name],
            'users' => $rows('users', ['email', 'password']),
            'patients' => $rows('patients', ['first_name', 'phone', 'allergies', 'antecedents_medical']),
            'appointments' => $rows('appointments', ['patient_id', 'public_id', 'status']),
            'consultations' => $rows('consultations', ['patient_id', 'payment_amount_minor']),
            'payments' => $rows('payments', ['consultation_id', 'amount_minor']),
            'documents' => $rows('documents', ['consultation_id', 'file_path']),
            'acts' => count($rows('acts', ['name'])),
            'medications' => count($rows('medications', ['id'])),
        ];
    }

    /**
     * Calls the real export API as the owner and keeps every answer, so the
     * PC can be served them after the database is emptied.
     */
    private function recordServerAnswers(User $owner): void
    {
        Sanctum::actingAs($owner);
        $manifest = $this->getJson('/api/v1/cabinet-transfer/manifest')->assertOk();
        $this->server['/api/v1/cabinet-transfer/manifest'] = ['status' => 200, 'body' => $manifest->getContent()];

        foreach (CabinetTransferCatalog::allTables() as $table) {
            $after = 0;

            do {
                $path = "/api/v1/cabinet-transfer/tables/{$table}";
                $page = $this->getJson("{$path}?after={$after}")->assertOk();
                $this->server["{$path}?after={$after}"] = ['status' => 200, 'body' => $page->getContent()];
                $after = $page->json('next_after');
            } while (is_int($after));
        }

        foreach ($manifest->json('files') as $file) {
            $response = $this->get('/api/v1/cabinet-transfer/files/'.$file['key'])->assertOk();
            $this->server['/api/v1/cabinet-transfer/files/'.$file['key']] = [
                'status' => 200,
                'body' => (string) file_get_contents($response->baseResponse->getFile()->getPathname()),
            ];
        }

        $this->assertCount(1, $manifest->json('files'));
        app('auth')->forgetGuards();
    }

    private function fakeServer(): void
    {
        $entitlement = $this->signedEntitlement([
            'owner_email' => self::OWNER,
            'hub_id' => app(MachineFingerprintService::class)->installationId(),
        ]);

        Http::fake(function (HttpRequest $request) use ($entitlement) {
            $path = Str::after($request->url(), self::CLOUD);

            return match (true) {
                $path === '/api/v1/desktop/activate' => Http::response([
                    'entitlement' => $entitlement,
                    'token' => 'jeton-du-cabinet',
                    'account' => ['email' => self::OWNER, 'cabinet_name' => 'Cabinet Ibn Sina'],
                    'cabinet' => ['name' => 'Cabinet Ibn Sina', 'owner_email' => self::OWNER, 'seat_limit' => 3],
                ]),
                $path === '/api/v1/cabinet/seats' => Http::response(['data' => [
                    'seat_limit' => 3, 'seats_in_use' => 2, 'owner_email' => self::OWNER,
                ]]),
                $path === '/api/v1/cabinet-transfer/complete' => Http::response(['transferred_at' => now()->toIso8601String()]),
                isset($this->server[$path]) => Http::response($this->server[$path]['body'], $this->server[$path]['status']),
                default => Http::response(['message' => 'unexpected '.$path], 404),
            };
        });
    }
}

<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\BilanTemplate;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Exam;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reusable exam selections saved from the bilan editor: a practitioner names
 * a set of exams once and re-applies it from the picker afterwards.
 */
class BilanTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function doctor(?Cabinet $cabinet = null): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet?->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole(RoleName::DOCTOR->value);

        return $user;
    }

    /**
     * @return list<Exam>
     */
    private function exams(int $count, ?Cabinet $cabinet = null): array
    {
        $exams = [];

        for ($index = 1; $index <= $count; $index++) {
            // forceCreate: cabinet_id is deliberately not fillable, and the
            // BelongsToCabinet hook only assigns it for an authenticated user.
            $exams[] = Exam::query()->forceCreate([
                'cabinet_id' => $cabinet?->getKey(),
                'name' => 'Examen '.$index,
                'category' => 'labo',
                'is_active' => true,
            ]);
        }

        return $exams;
    }

    public function test_a_practitioner_saves_a_named_selection_of_exams(): void
    {
        $doctor = $this->doctor();
        [$first, $second] = $this->exams(2);

        $this->actingAs($doctor)
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Bilan pré-opératoire',
                'exam_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $template = BilanTemplate::query()->sole();

        $this->assertSame('Bilan pré-opératoire', $template->name);
        // Submission order is preserved: it is the order the exams print in.
        $this->assertSame([$second->id, $first->id], $template->exam_ids);
    }

    public function test_a_template_needs_at_least_one_exam(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor)
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Vide',
                'exam_ids' => [],
            ])
            ->assertSessionHasErrors('exam_ids');

        $this->assertSame(0, BilanTemplate::query()->count());
    }

    public function test_two_templates_in_one_cabinet_cannot_share_a_name(): void
    {
        $cabinet = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $doctor = $this->doctor($cabinet);
        [$exam] = $this->exams(1, $cabinet);

        $payload = ['name' => 'Bilan standard', 'exam_ids' => [$exam->id]];

        $this->actingAs($doctor)
            ->post(route('app.bilan-templates.store'), $payload)
            ->assertRedirect();

        $this->actingAs($doctor)
            ->post(route('app.bilan-templates.store'), $payload)
            ->assertSessionHasErrors([
                // Shown verbatim in the dialog, so it must read in French.
                'name' => 'Un modèle portant ce nom existe déjà.',
            ]);

        $this->assertSame(1, BilanTemplate::query()->count());
    }

    public function test_the_same_name_is_free_in_another_cabinet(): void
    {
        $cabinetA = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $cabinetB = Cabinet::query()->create(['name' => 'Cabinet B', 'status' => 'active']);
        [$examA] = $this->exams(1, $cabinetA);
        [$examB] = $this->exams(1, $cabinetB);

        $this->actingAs($this->doctor($cabinetA))
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Bilan standard',
                'exam_ids' => [$examA->id],
            ])
            ->assertRedirect();

        $this->actingAs($this->doctor($cabinetB))
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Bilan standard',
                'exam_ids' => [$examB->id],
            ])
            ->assertRedirect();

        $this->assertSame(2, BilanTemplate::withoutCabinetScope()->count());
    }

    public function test_exams_from_another_cabinet_are_rejected(): void
    {
        $cabinetA = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $cabinetB = Cabinet::query()->create(['name' => 'Cabinet B', 'status' => 'active']);
        [$mine] = $this->exams(1, $cabinetA);
        [$theirs] = $this->exams(1, $cabinetB);

        $this->actingAs($this->doctor($cabinetA))
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Mixte',
                'exam_ids' => [$mine->id, $theirs->id],
            ])
            ->assertRedirect();

        // The foreign exam is silently dropped rather than stored as a
        // dangling id that would render as a blank line.
        $this->assertSame([$mine->id], BilanTemplate::query()->sole()->exam_ids);
    }

    public function test_a_template_built_only_from_foreign_exams_is_refused(): void
    {
        $cabinetA = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $cabinetB = Cabinet::query()->create(['name' => 'Cabinet B', 'status' => 'active']);
        [$theirs] = $this->exams(1, $cabinetB);

        $this->actingAs($this->doctor($cabinetA))
            ->post(route('app.bilan-templates.store'), [
                'name' => 'Emprunté',
                'exam_ids' => [$theirs->id],
            ])
            ->assertSessionHasErrors('exam_ids');

        $this->assertSame(0, BilanTemplate::withoutCabinetScope()->count());
    }

    public function test_a_practitioner_deletes_their_own_template(): void
    {
        $doctor = $this->doctor();
        [$exam] = $this->exams(1);

        $template = BilanTemplate::query()->create([
            'name' => 'À supprimer',
            'exam_ids' => [$exam->id],
        ]);

        $this->actingAs($doctor)
            ->delete(route('app.bilan-templates.destroy', $template))
            ->assertRedirect();

        $this->assertSame(0, BilanTemplate::query()->count());
    }

    public function test_a_template_from_another_cabinet_is_not_reachable(): void
    {
        $cabinetA = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $cabinetB = Cabinet::query()->create(['name' => 'Cabinet B', 'status' => 'active']);
        [$exam] = $this->exams(1, $cabinetB);

        $theirs = BilanTemplate::query()->forceCreate([
            'cabinet_id' => $cabinetB->getKey(),
            'name' => 'Leur modèle',
            'exam_ids' => [$exam->id],
        ]);

        $this->actingAs($this->doctor($cabinetA))
            ->delete(route('app.bilan-templates.destroy', $theirs))
            ->assertNotFound();

        $this->assertSame(1, BilanTemplate::withoutCabinetScope()->count());
    }

    public function test_the_workspace_lists_templates_and_drops_deleted_exams(): void
    {
        $doctor = $this->doctor();
        [$kept, $removed] = $this->exams(2);

        BilanTemplate::query()->create([
            'name' => 'Bilan rénal',
            'exam_ids' => [$kept->id, $removed->id],
        ]);

        // An exam deleted after the template was saved must not leave a hole.
        $removed->delete();

        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-03-01 09:00:00'),
            'status' => 'in_progress',
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Workspace')
                ->has('bilanTemplates', 1)
                ->where('bilanTemplates.0.name', 'Bilan rénal')
                ->where('bilanTemplates.0.exam_ids', [$kept->id]),
            );
    }
}

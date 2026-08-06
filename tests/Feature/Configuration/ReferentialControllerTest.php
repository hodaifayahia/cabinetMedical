<?php

namespace Tests\Feature\Configuration;

use App\Enums\RoleName;
use App\Models\BilanType;
use App\Models\Exam;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferentialControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole(RoleName::ADMINISTRATOR->value);
    }

    public function test_bilan_categories_use_name_and_description_fields(): void
    {
        $this->actingAs($this->user)
            ->get(route('app.configuration.referentials.index', 'bilan-types'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('meta.fields.0.key', 'name')
                ->where('meta.fields.1.key', 'description')
                ->where('meta.fields.1.type', 'textarea')
                ->where('meta.fields.1.required', true),
            );

        $this->actingAs($this->user)
            ->post(route('app.configuration.referentials.store', 'bilan-types'), [
                'name' => 'Biology',
                'description' => 'Biological analyses.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bilan_types', [
            'name' => 'Biology',
            'description' => 'Biological analyses.',
        ]);
    }

    public function test_exam_category_is_selected_from_active_bilan_categories(): void
    {
        BilanType::query()->create([
            'name' => 'Biology',
            'description' => 'Biological analyses.',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.configuration.referentials.index', 'exams'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('meta.fields.1.type', 'select')
                ->where('meta.fields.1.options.0.value', 'Biology'),
            );

        $this->actingAs($this->user)
            ->post(route('app.configuration.referentials.store', 'exams'), [
                'name' => 'Complete blood count',
                'category' => 'Unknown',
            ])
            ->assertSessionHasErrors('category');

        $this->actingAs($this->user)
            ->post(route('app.configuration.referentials.store', 'exams'), [
                'name' => 'Complete blood count',
                'category' => 'Biology',
            ])
            ->assertRedirect();

        $this->assertSame('Biology', Exam::query()->sole()->category);
    }
}

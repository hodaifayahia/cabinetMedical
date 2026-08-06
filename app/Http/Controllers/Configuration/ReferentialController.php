<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ReferentialRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BilanType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReferentialController extends Controller
{
    /**
     * List a referential's rows (searchable + paginated).
     */
    public function index(Request $request, string $referential): Response
    {
        $definition = $this->definition($referential);
        $search = trim((string) $request->string('search'));

        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $orderColumn = $definition['searchable'][0] ?? 'id';

        $items = $model::query()
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $definition, $search))
            ->orderBy($orderColumn)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Model $item): array => $this->transform($item, $definition));

        return Inertia::render('configuration/Referential', [
            'items' => $items,
            'filters' => ['search' => $search],
            'meta' => [
                'slug' => $referential,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'section' => $definition['section'],
                'columns' => $definition['columns'],
                'fields' => $this->fields($referential, $definition),
            ],
        ]);
    }

    /**
     * Create a new row.
     */
    public function store(Request $request, string $referential): RedirectResponse
    {
        $definition = $this->definition($referential);
        $data = $request->validate($definition['rules']);

        /** @var class-string<Model> $model */
        $model = $definition['model'];
        DB::transaction(function () use ($model, $data, $definition, $referential): void {
            $record = $model::query()->create($this->prepare($data, $definition));
            AuditLog::record('configuration.referential_created', $record, [
                'referential' => $referential,
                'keys' => array_keys($data),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Enregistrement ajouté.']);

        return back();
    }

    /**
     * Update an existing row.
     */
    public function update(Request $request, string $referential, int $id): RedirectResponse
    {
        $definition = $this->definition($referential);
        $data = $request->validate($definition['rules']);

        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $record = $model::query()->findOrFail($id);
        DB::transaction(function () use ($record, $data, $definition, $referential): void {
            $record->update($this->prepare($data, $definition));
            AuditLog::record('configuration.referential_updated', $record, [
                'referential' => $referential,
                'keys' => array_keys($data),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modifications enregistrées.']);

        return back();
    }

    /**
     * Delete a row.
     */
    public function destroy(string $referential, int $id): RedirectResponse
    {
        $definition = $this->definition($referential);

        /** @var class-string<Model> $model */
        $model = $definition['model'];
        DB::transaction(function () use ($model, $id, $referential): void {
            $record = $model::query()->findOrFail($id);
            AuditLog::record('configuration.referential_removed', $record, [
                'referential' => $referential,
            ]);
            $record->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Enregistrement supprimé.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $referential): array
    {
        $definition = ReferentialRegistry::for($referential);

        abort_if($definition === null, 404);

        return $definition;
    }

    /**
     * Resolve fields that depend on another referential.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function fields(string $referential, array $definition): array
    {
        if ($referential !== 'exams') {
            return array_values($definition['fields']);
        }

        $options = BilanType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'description'])
            ->map(fn (BilanType $category): array => [
                'value' => $category->name,
                'label' => $category->name,
                'description' => $category->description,
            ])
            ->values()
            ->all();

        return array_values(array_map(function (array $field) use ($options): array {
            if (($field['options_source'] ?? null) !== 'bilan-categories') {
                return $field;
            }

            unset($field['options_source']);
            $field['options'] = $options;

            return $field;
        }, $definition['fields']));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $definition
     * @return Builder<Model>
     */
    private function applySearch(Builder $query, array $definition, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($definition, $search): void {
            foreach ($definition['searchable'] as $column) {
                $query->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * Build the row payload sent to the frontend (money columns → major units).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function transform(Model $item, array $definition): array
    {
        $row = ['id' => $item->getKey()];

        foreach ($definition['fields'] as $field) {
            $key = $field['key'];

            if (isset($definition['money'][$key])) {
                $minor = $item->getAttribute($definition['money'][$key]);
                $row[$key] = $minor === null ? null : $minor / 100;

                continue;
            }

            $row[$key] = $item->getAttribute($key);
        }

        return $row;
    }

    /**
     * Convert validated form data into persistable attributes (major → minor).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function prepare(array $data, array $definition): array
    {
        $attributes = [];

        foreach ($data as $key => $value) {
            if (isset($definition['money'][$key])) {
                $attributes[$definition['money'][$key]] = ($value === null || $value === '')
                    ? null
                    : (int) round(((float) $value) * 100);

                continue;
            }

            $attributes[$key] = $value === '' ? null : $value;
        }

        return $attributes;
    }
}

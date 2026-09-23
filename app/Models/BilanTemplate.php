<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A named, reusable set of exams saved from the bilan editor, so a frequently
 * prescribed work-up can be re-applied in one click.
 *
 * Only exam ids are stored: names and categories are resolved from `exams` at
 * read time, so a renamed exam stays correct everywhere it is referenced.
 *
 * @property list<int> $exam_ids
 */
#[Fillable(['name', 'exam_ids'])]
class BilanTemplate extends Model
{
    use BelongsToCabinet;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['exam_ids' => 'array'];
    }

    /**
     * The stored ids, narrowed to exams that still exist and are active, in
     * the order the practitioner saved them.
     *
     * @param  Collection<int, Exam>  $exams  keyed by id
     * @return list<int>
     */
    public function resolvedExamIds(Collection $exams): array
    {
        return array_values(array_filter(
            array_map('intval', $this->exam_ids ?? []),
            static fn (int $id): bool => $exams->has($id),
        ));
    }
}

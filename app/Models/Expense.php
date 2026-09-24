<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One operating cost of the cabinet (rent, salaries, supplies…).
 *
 * @property ExpenseCategory $category
 * @property string $label
 * @property int $amount_minor
 * @property CarbonImmutable $spent_on
 * @property string|null $method
 * @property string|null $supplier
 * @property string|null $notes
 * @property bool $is_recurring
 */
#[Fillable([
    'cabinet_id',
    'public_id',
    'category',
    'label',
    'amount_minor',
    'spent_on',
    'method',
    'supplier',
    'notes',
    'is_recurring',
    'created_by',
])]
class Expense extends Model
{
    use BelongsToCabinet;

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            $expense->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount_minor' => 'integer',
            'spent_on' => 'immutable_date',
            'is_recurring' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Inclusive date range. whereDate() because SQLite stores the date as
     * « Y-m-d 00:00:00 », which a plain BETWEEN … 'Y-m-d' would drop on
     * the last day.
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function spentBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

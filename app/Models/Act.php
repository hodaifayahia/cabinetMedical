<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'price_minor', 'category', 'is_active'])]
class Act extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'is_active' => 'boolean'];
    }
}

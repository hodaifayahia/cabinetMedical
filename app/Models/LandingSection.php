<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    protected $fillable = [
        'locale',
        'slug',
        'section_type',
        'eyebrow',
        'title',
        'body',
        'cta_label',
        'cta_url',
        'image_url',
        'items',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}

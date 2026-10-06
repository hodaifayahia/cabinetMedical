<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A cabinet-authored consultation document template.
 *
 * It mirrors the shape of a built-in catalogue entry (category, group, title,
 * body, default paper size) so ClinicalDocumentTemplateCatalog can merge active
 * rows straight into the picker and ClinicalDocumentManager can render them
 * exactly like a built-in template.
 *
 * @property string|null $public_id
 * @property string $template_key
 * @property string $category
 * @property string|null $group
 * @property string $title
 * @property string $body
 * @property string $body_format 'text' (legacy line-based body) | 'html' (rich editor)
 * @property string $paper_size
 * @property bool $is_active
 */
#[Fillable([
    'template_key',
    'category',
    'group',
    'title',
    'body',
    'body_format',
    'paper_size',
    'is_active',
    'created_by',
])]
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use BelongsToCabinet, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            if (blank($template->public_id)) {
                $template->public_id = (string) Str::uuid7();
            }

            // A stable, collision-free catalogue key. The "custom-" prefix keeps
            // it clear of every built-in key and of the bilan-type-/exam- keys.
            if (blank($template->template_key)) {
                $template->template_key = 'custom-'.$template->public_id;
            }

            if (blank($template->paper_size)) {
                $template->paper_size = 'A4';
            }

            if (blank($template->body_format)) {
                $template->body_format = 'text';
            }
        });
    }

    protected static function newFactory(): DocumentTemplateFactory
    {
        return DocumentTemplateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

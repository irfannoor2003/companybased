<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentTemplate extends Model
{
    /**
     * `style` was missing from this list until this pass, which made the style
     * picker on Settings -> Templates a complete no-op: the form posted a style,
     * `TemplateController` passed it to `create()` / `update()`, and mass
     * assignment discarded it because the column was not fillable. Every template
     * therefore saved with the column default of `rail`, and the visual design a
     * customer chose never reached the renderer -- the same "convincing lie"
     * `PdfLayout` was written to close, one level further in.
     *
     * `ModelContractTest` only asserts that every fillable column exists, not that
     * every column a controller writes is fillable, so nothing caught it. The
     * regression is pinned by `PdfLayoutTest::test_a_style_chosen_on_the_settings
     * _screen_is_actually_persisted`.
     */
    protected $fillable = [
        'name', 'slug', 'type', 'style', 'description', 'colors', 'layout',
        'header_html', 'footer_html', 'css', 'is_default', 'is_system',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'layout' => 'array',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DocumentTemplate $template) {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public static function getDefault(string $type): ?self
    {
        return static::where('type', $type)->where('is_default', true)->first();
    }

    public static function getForType(string $type)
    {
        return static::where('type', $type)->orderBy('name')->get();
    }

    public function getTypeLabelAttribute(): string
    {
        return ucwords(str_replace('_', ' ', $this->type));
    }

    /**
     * The template's colours, never null.
     *
     * The column is nullable, and this accessor used to read `$this->attributes['colors']`
     * without checking it existed -- so a template row saved without a palette threw
     * "Undefined array key" the moment anything rendered it, which is every row of
     * Settings -> Templates rather than the one row that caused it. The settings
     * screen and the index both read `colors['primary']`, so null is not an option
     * for callers; the defaults below are what a missing palette means.
     *
     * @return array<string, string>
     */
    public function getColorsAttribute(): array
    {
        $colors = $this->attributes['colors'] ?? null;

        if (blank($colors)) {
            return [
                'primary' => '#4f46e5',
                'accent' => '#0ea5e9',
                'text' => '#1f2937',
            ];
        }

        $decoded = json_decode((string) $colors, true);

        return is_array($decoded)
            ? $decoded + ['primary' => '#4f46e5', 'accent' => '#0ea5e9', 'text' => '#1f2937']
            : [
                'primary' => '#4f46e5',
                'accent' => '#0ea5e9',
                'text' => '#1f2937',
            ];
    }
}

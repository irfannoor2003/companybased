<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'name', 'slug', 'type', 'description', 'colors', 'layout',
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

    public function getColorsAttribute(): array
    {
        return $this->attributes['colors'] ? json_decode($this->attributes['colors'], true) : [
            'primary' => '#4f46e5',
            'accent' => '#0ea5e9',
            'text' => '#1f2937',
        ];
    }
}

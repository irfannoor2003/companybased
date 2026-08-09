<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomReport extends Model
{
    protected $fillable = [
        'name', 'description', 'module', 'fields', 'filters', 'user_id'
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'filters' => 'array',
        ];
    }
}

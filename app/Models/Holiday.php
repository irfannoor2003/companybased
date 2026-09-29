<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = ['holiday_date', 'name', 'description', 'is_default', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

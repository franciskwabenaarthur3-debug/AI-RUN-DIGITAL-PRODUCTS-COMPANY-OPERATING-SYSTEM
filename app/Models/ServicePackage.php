<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'scope',
        'exclusions',
        'delivery_template',
        'base_price_minor',
        'currency',
        'estimated_days',
        'revision_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'exclusions' => 'array',
            'delivery_template' => 'array',
            'base_price_minor' => 'integer',
            'estimated_days' => 'integer',
            'revision_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

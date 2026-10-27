<?php

namespace App\Models;

use App\Enums\ApprovalPolicy;
use App\Enums\OperatingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-row table holding company-wide configuration.
 */
class CompanySetting extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'company_name' => 'Company OS',
        'default_currency' => 'USD',
        'timezone' => 'UTC',
        'approval_policy' => 'strict',
        'monthly_ai_budget_minor' => 0,
        'operating_status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'approval_policy' => ApprovalPolicy::class,
            'operating_status' => OperatingStatus::class,
            'monthly_ai_budget_minor' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::query()->create();
    }

    public function isPaused(): bool
    {
        return $this->operating_status === OperatingStatus::Paused;
    }
}

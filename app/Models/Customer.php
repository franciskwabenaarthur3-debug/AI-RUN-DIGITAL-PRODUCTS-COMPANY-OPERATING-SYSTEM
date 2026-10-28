<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['company_name', 'primary_contact', 'email'];

    /**
     * billing_details is excluded; it is set explicitly by founder-only paths.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'primary_contact',
        'email',
        'phone',
        'status',
    ];

    protected $hidden = ['billing_details'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'billing_details' => 'encrypted',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : strtolower(trim($value)));
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class)->latest('id');
    }
}

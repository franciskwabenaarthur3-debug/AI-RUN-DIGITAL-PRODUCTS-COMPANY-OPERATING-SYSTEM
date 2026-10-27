<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\ResearchStatus;
use App\Models\Concerns\Searchable;
use App\Support\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['company_name', 'contact_name', 'email', 'website_domain'];

    /**
     * Status, opt-out and conversion fields are changed only by LeadService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'contact_name',
        'email',
        'phone',
        'website',
        'source',
        'source_reference',
        'research_status',
        'qualification_score',
        'next_follow_up_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'new',
        'research_status' => 'pending',
        'do_not_contact' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'research_status' => ResearchStatus::class,
            'qualification_score' => 'integer',
            'next_follow_up_at' => 'datetime',
            'do_not_contact' => 'boolean',
            'opted_out_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : strtolower(trim($value)));
    }

    protected function website(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => [
            'website' => $value === null || trim($value) === '' ? null : trim($value),
            'website_domain' => Domain::fromUrl($value),
        ]);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the lead may enter any outreach workflow.
     */
    public function canBeContacted(): bool
    {
        return ! $this->do_not_contact && $this->status !== LeadStatus::Unsubscribed;
    }

    public function isFollowUpDue(): bool
    {
        return $this->next_follow_up_at !== null && $this->next_follow_up_at->isPast();
    }

    public function scopeFollowUpDue(Builder $query): Builder
    {
        return $query->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now())
            ->whereNotIn('status', [LeadStatus::Converted, LeadStatus::Unsubscribed, LeadStatus::Lost, LeadStatus::Disqualified]);
    }
}

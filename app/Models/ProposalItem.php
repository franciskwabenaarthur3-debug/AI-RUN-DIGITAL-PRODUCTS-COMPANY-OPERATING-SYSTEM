<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A priced line. Totals are computed by ProposalPricing, never entered. */
class ProposalItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item) {
            $status = Proposal::query()->whereKey($item->proposal_id)->toBase()->value('status');

            if ($status !== null && $status !== ProposalStatus::Draft->value) {
                throw new LogicException('Items of a proposal that left draft cannot change.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}

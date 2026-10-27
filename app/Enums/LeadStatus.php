<?php

namespace App\Enums;

/**
 * Lead lifecycle. Transitions are explicit; anything not listed is refused.
 *
 * `converted` is reached only through lead conversion and `unsubscribed`
 * only through the opt-out action, so their side effects always happen.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Researched = 'researched';
    case Qualified = 'qualified';
    case OutreachDrafted = 'outreach_drafted';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case Converted = 'converted';
    case Disqualified = 'disqualified';
    case Unsubscribed = 'unsubscribed';
    case Lost = 'lost';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Researched, self::Qualified, self::Disqualified, self::Unsubscribed, self::Lost],
            self::Researched => [self::Qualified, self::Disqualified, self::Unsubscribed, self::Lost],
            self::Qualified => [self::OutreachDrafted, self::Converted, self::Disqualified, self::Unsubscribed, self::Lost],
            self::OutreachDrafted => [self::Contacted, self::Qualified, self::Unsubscribed, self::Lost],
            self::Contacted => [self::Replied, self::Converted, self::Unsubscribed, self::Lost],
            self::Replied => [self::Converted, self::Unsubscribed, self::Lost],
            self::Disqualified => [self::New],
            self::Lost => [self::Qualified],
            self::Converted, self::Unsubscribed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Targets a user may pick directly from the status control.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            fn (self $s) => ! in_array($s, [self::Converted, self::Unsubscribed], true),
        ));
    }

    /** States that represent reaching out to the contact. */
    public function isOutreach(): bool
    {
        return in_array($this, [self::OutreachDrafted, self::Contacted], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

<?php

namespace App\Enums;

/**
 * Proposal lifecycle, per version. Content can change only in draft; any
 * later change is a new version that needs its own approval.
 */
enum ProposalStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Accepted = 'accepted';
    /** The founder rejected the version at approval. */
    case Rejected = 'rejected';
    /** The recipient asked for changes. */
    case ChangesRequested = 'changes_requested';
    case Expired = 'expired';
    /** The recipient declined. */
    case Declined = 'declined';
    /** Withdrawn by the company, or superseded by a newer version before sending. */
    case Withdrawn = 'withdrawn';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Withdrawn],
            // pending_approval -> pending_approval and approved -> pending_approval
            // re-request approval after the earlier request expired or failed.
            self::PendingApproval => [self::PendingApproval, self::Approved, self::Rejected, self::Withdrawn],
            self::Approved => [self::PendingApproval, self::Sent, self::Withdrawn],
            self::Sent => [self::Accepted, self::Declined, self::ChangesRequested, self::Expired, self::Withdrawn],
            self::Accepted, self::Rejected, self::ChangesRequested, self::Expired, self::Declined, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** States from which a new version may be started. */
    public function allowsNewVersion(): bool
    {
        return in_array($this, [self::PendingApproval, self::Approved, self::Rejected, self::ChangesRequested, self::Expired], true);
    }

    /** Whether the recipient may already have this version. */
    public function wasSent(): bool
    {
        return in_array($this, [self::Sent, self::Accepted, self::Declined, self::ChangesRequested, self::Expired], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

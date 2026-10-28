<?php

namespace App\Enums;

/**
 * Project lifecycle. Some steps have gates enforced by ProjectService:
 * client review needs a verified staging release, delivered is set only
 * by a verified production release, and closing needs the handover
 * checklist done.
 */
enum ProjectStatus: string
{
    case Created = 'created';
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case InternalReview = 'internal_review';
    case ClientReview = 'client_review';
    case ChangesRequested = 'changes_requested';
    case ApprovedForRelease = 'approved_for_release';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Blocked = 'blocked';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        $pause = [self::Blocked, self::OnHold, self::Cancelled];

        return match ($this) {
            self::Created => [self::Planning, self::OnHold, self::Cancelled],
            self::Planning => [self::InProgress, ...$pause],
            self::InProgress => [self::InternalReview, ...$pause],
            self::InternalReview => [self::ClientReview, self::InProgress, ...$pause],
            self::ClientReview => [self::ChangesRequested, self::ApprovedForRelease, ...$pause],
            self::ChangesRequested => [self::InProgress, ...$pause],
            self::ApprovedForRelease => [self::Delivered, self::InProgress, ...$pause],
            self::Delivered => [self::Closed],
            self::Blocked, self::OnHold => [self::Planning, self::InProgress, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Targets a person picks directly; delivered comes only from a verified release. */
    public function manualTransitions(): array
    {
        return array_values(array_filter($this->allowedTransitions(), fn (self $s) => $s !== self::Delivered));
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Delivered, self::Closed, self::Cancelled], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

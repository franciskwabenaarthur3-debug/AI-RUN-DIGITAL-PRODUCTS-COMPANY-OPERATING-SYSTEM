<?php

namespace App\Enums;

/**
 * Task lifecycle. Only the transitions listed here are possible; code
 * changes status through TaskService/TaskRunner, never by direct update.
 */
enum TaskStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingReview = 'awaiting_review';
    case AwaitingApproval = 'awaiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Queued, self::Blocked, self::Cancelled],
            self::Queued => [self::Running, self::Blocked, self::Cancelled],
            // Running → Queued is an automatic retry.
            self::Running => [self::Completed, self::AwaitingReview, self::AwaitingApproval, self::Failed, self::Blocked, self::Queued],
            self::AwaitingReview => [self::Completed, self::Failed, self::Cancelled],
            self::AwaitingApproval => [self::Completed, self::Failed, self::Cancelled],
            self::Blocked => [self::Queued, self::Cancelled],
            // Failed → Queued is a manual retry.
            self::Failed => [self::Queued, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * A task done by a person (no agent) skips the queue: it goes from
     * created or blocked straight to completed when the person marks it done.
     */
    public function canBeCompletedByPerson(): bool
    {
        return in_array($this, [self::Created, self::Blocked], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

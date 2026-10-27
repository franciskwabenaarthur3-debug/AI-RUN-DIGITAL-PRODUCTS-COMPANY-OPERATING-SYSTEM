<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use PHPUnit\Framework\TestCase;

class TaskStatusTest extends TestCase
{
    public function test_normal_lifecycle(): void
    {
        $this->assertTrue(TaskStatus::Created->canTransitionTo(TaskStatus::Queued));
        $this->assertTrue(TaskStatus::Queued->canTransitionTo(TaskStatus::Running));
        $this->assertTrue(TaskStatus::Running->canTransitionTo(TaskStatus::AwaitingReview));
        $this->assertTrue(TaskStatus::AwaitingReview->canTransitionTo(TaskStatus::Completed));
    }

    public function test_arbitrary_jumps_are_refused(): void
    {
        $this->assertFalse(TaskStatus::Created->canTransitionTo(TaskStatus::Completed));
        $this->assertFalse(TaskStatus::Created->canTransitionTo(TaskStatus::Running));
        $this->assertFalse(TaskStatus::Queued->canTransitionTo(TaskStatus::Completed));
        $this->assertFalse(TaskStatus::Running->canTransitionTo(TaskStatus::Cancelled));
        $this->assertFalse(TaskStatus::Failed->canTransitionTo(TaskStatus::Completed));
    }

    public function test_completed_and_cancelled_are_terminal(): void
    {
        $this->assertTrue(TaskStatus::Completed->isTerminal());
        $this->assertTrue(TaskStatus::Cancelled->isTerminal());
        $this->assertFalse(TaskStatus::Failed->isTerminal());
    }
}

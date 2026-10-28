<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MaintenanceTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Something a maintenance visit found. Kept as recorded once its task closes. */
class MaintenanceFinding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'risky' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $finding) {
            $status = MaintenanceTask::query()->whereKey($finding->maintenance_task_id)->toBase()->value('status');

            if ($status !== null && ! MaintenanceTaskStatus::from($status)->isOpen()) {
                throw new LogicException('Findings of a closed maintenance task cannot change.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}

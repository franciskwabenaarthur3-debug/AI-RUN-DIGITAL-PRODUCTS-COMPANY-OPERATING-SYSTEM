<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\ReleaseEnvironment;
use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A staging or production release of a project. Written only by
 * ReleaseService. What it releases is frozen once it leaves draft.
 */
class ProjectRelease extends Model
{
    public const CONTENT = ['project_id', 'environment', 'version', 'commit_reference', 'target_url', 'release_notes', 'rollback_plan'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'environment' => ReleaseEnvironment::class,
            'status' => ReleaseStatus::class,
            'health_detail' => 'array',
            'submitted_at' => 'datetime',
            'deployed_at' => 'datetime',
            'health_checked_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $release) {
            if ($release->getRawOriginal('status') !== ReleaseStatus::Draft->value && $release->isDirty(self::CONTENT)) {
                throw new LogicException('A release cannot change after it leaves draft. Create a new version.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ReleaseCheck::class)->orderBy('id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** Mandatory checks that have not passed. */
    public function blockingChecks()
    {
        return $this->checks->filter(fn (ReleaseCheck $c) => $c->mandatory && $c->status !== CheckStatus::Passed)->values();
    }

    public function isProduction(): bool
    {
        return $this->environment === ReleaseEnvironment::Production;
    }

    public function label(): string
    {
        return "{$this->environment->label()} {$this->version}";
    }
}

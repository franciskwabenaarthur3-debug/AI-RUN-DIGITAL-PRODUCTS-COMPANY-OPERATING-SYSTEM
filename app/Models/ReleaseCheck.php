<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\ReleaseEnvironment;
use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A quality check result on a release. On a production release the
 * results are frozen once it leaves draft, because the founder approves
 * them; on staging they can be recorded while the release is in use.
 */
class ReleaseCheck extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CheckStatus::class,
            'mandatory' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $check) {
            $release = ProjectRelease::query()->whereKey($check->project_release_id)->toBase()->first(['environment', 'status']);

            if (! $release || $release->status === ReleaseStatus::Draft->value) {
                return;
            }
            $closed = in_array($release->status, [ReleaseStatus::Cancelled->value, ReleaseStatus::RolledBack->value], true);
            if ($release->environment === ReleaseEnvironment::Production->value || $closed) {
                throw new LogicException('Checks of this release can no longer change.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(ProjectRelease::class, 'project_release_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}

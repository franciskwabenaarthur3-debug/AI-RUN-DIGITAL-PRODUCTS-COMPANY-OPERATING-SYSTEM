<?php

namespace App\Models;

use App\Enums\DeliverableStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDeliverable extends Model
{
    public const TYPES = ['website', 'design', 'document', 'theme', 'plugin', 'source_code', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DeliverableStatus::class,
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }
}

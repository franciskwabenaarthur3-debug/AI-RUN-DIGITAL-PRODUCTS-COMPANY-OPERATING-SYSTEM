<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status, attempts and results are written only by TaskService and
 * TaskRunner; they are not mass assignable.
 */
class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'parent_task_id',
        'title',
        'description',
        'input',
        'priority',
        'due_at',
    ];

    protected $attributes = [
        'status' => 'created',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'input' => 'array',
            'result' => 'array',
            'due_at' => 'datetime',
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'auto_retries' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class)->orderByDesc('attempt');
    }

    /** Tasks this one waits for. */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    /** Tasks waiting for this one. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    public function hasOpenDependencies(): bool
    {
        return $this->dependencies()->where('status', '!=', TaskStatus::Completed)->exists();
    }

    public function creatorName(): string
    {
        if ($this->created_by_type === 'user' && $this->created_by_id) {
            return User::query()->whereKey($this->created_by_id)->value('name') ?? 'User #'.$this->created_by_id;
        }

        if ($this->created_by_type === 'agent' && $this->created_by_id) {
            return Agent::query()->whereKey($this->created_by_id)->value('name') ?? 'Agent #'.$this->created_by_id;
        }

        return $this->created_by_type;
    }
}

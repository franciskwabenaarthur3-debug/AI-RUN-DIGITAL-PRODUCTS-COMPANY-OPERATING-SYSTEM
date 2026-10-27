<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // project_id is added with the projects module (Phase 7).
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('input')->nullable();
            $table->string('status', 32)->default('created')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->json('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Automatic retries used in the current queue cycle; reset on manual retry.
            $table->unsignedTinyInteger('auto_retries')->default(0);
            $table->string('created_by_type', 32);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'depends_on_task_id']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16)->index();
            $table->string('runtime', 32);
            $table->string('model_key', 100)->nullable();
            $table->string('instructions_version', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('deadline_at');
            $table->char('input_hash', 64);
            $table->json('output')->nullable();
            $table->string('error_code', 64)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('usage_metadata')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();

            // One run per attempt: a duplicate worker cannot record a second run.
            $table->unique(['task_id', 'attempt']);
        });

        Schema::create('model_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->bigInteger('estimated_cost_minor');
            // Filled from provider billing data when available; never guessed.
            $table->bigInteger('actual_cost_minor')->nullable();
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_usage');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
    }
};

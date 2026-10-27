<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 64)->unique();
            $table->string('role', 32)->unique();
            $table->text('description');
            $table->string('instructions_version', 32);
            // Which runtime executes this agent (e.g. "mock"; provider adapters arrive with the AI gateway).
            $table->string('runtime', 32)->default('mock');
            $table->string('model_key', 100)->nullable();
            $table->json('configuration')->nullable();
            $table->string('status', 16)->default('disabled')->index();
            // Limits enforced in application code by the task runner.
            $table->unsignedSmallInteger('max_steps')->default(10);
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->unsignedSmallInteger('timeout_seconds')->default(120);
            $table->unsignedInteger('max_tokens_per_run')->default(20000);
            $table->bigInteger('monthly_budget_minor')->default(0);
            $table->boolean('review_required')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name', 64);
            $table->string('resource_scope', 64)->default('*');
            $table->boolean('allowed')->default(false);
            $table->json('limits')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'tool_name', 'resource_scope']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('assigned_agent_id')->nullable()->after('next_follow_up_at')->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_agent_id');
        });
        Schema::dropIfExists('agent_permissions');
        Schema::dropIfExists('agents');
    }
};

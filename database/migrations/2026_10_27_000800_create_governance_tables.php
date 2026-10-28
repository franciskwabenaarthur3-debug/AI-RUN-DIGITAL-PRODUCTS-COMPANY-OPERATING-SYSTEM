<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 64)->index();
            $table->string('resource_type', 100)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            // The exact payload the approver sees, and its SHA-256 over canonical JSON.
            $table->json('payload_snapshot');
            $table->char('payload_hash', 64);
            $table->text('reason');
            $table->string('risk_level', 16);
            $table->text('rollback_plan');
            $table->json('evidence')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('execution_result')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            // One decision per request; a second decision is refused by the database.
            $table->foreignId('approval_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('reason')->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->string('tool_name', 64)->index();
            // Unique per tool: retries of the same call return the first result.
            $table->string('idempotency_key', 128);
            $table->string('status', 24)->index();
            $table->char('input_hash', 64);
            $table->json('input_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->string('external_reference')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['tool_name', 'idempotency_key']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_tool_calls_per_run')->default(20)->after('max_tokens_per_run');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('max_tool_calls_per_run');
        });
        Schema::dropIfExists('tool_executions');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};

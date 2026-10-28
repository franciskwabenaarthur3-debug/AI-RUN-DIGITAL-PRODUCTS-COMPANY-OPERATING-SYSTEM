<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            // Milestones and tasks a project for this package starts with.
            $table->json('delivery_template')->nullable()->after('exclusions');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            // One project per accepted proposal, enforced by the database.
            $table->foreignId('proposal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('created')->index();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('budget_minor');
            $table->char('currency', 3);
            $table->string('repository_url')->nullable();
            $table->string('staging_url')->nullable();
            $table->string('production_url')->nullable();
            $table->json('handover_checklist');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('proposals', function (Blueprint $table) {
            // Set on a change request: a proposal for more work on an existing project.
            $table->foreignId('project_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->date('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();
            $table->string('name', 200);
            $table->string('type', 32);
            // An http(s) link entered by a person; files are not stored by Company OS yet.
            $table->string('url')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->after('project_id')->constrained('project_milestones')->nullOnDelete();
            // A person doing the task instead of an agent.
            $table->foreignId('assignee_user_id')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('project_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('version', 64);
            $table->string('commit_reference', 100)->nullable();
            $table->string('target_url');
            $table->text('release_notes');
            $table->text('rollback_plan');
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->text('deployment_note')->nullable();
            $table->string('health_status', 16)->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->json('health_detail')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('rollback_note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'environment', 'version']);
        });

        Schema::create('release_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_release_id')->constrained()->cascadeOnDelete();
            $table->string('check_key', 48);
            $table->string('label', 150);
            $table->boolean('mandatory');
            $table->string('status', 16)->default('pending');
            $table->text('evidence')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['project_release_id', 'check_key']);
        });

        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_events');
        Schema::dropIfExists('release_checks');
        Schema::dropIfExists('project_releases');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_user_id');
            $table->dropConstrainedForeignId('milestone_id');
            $table->dropConstrainedForeignId('project_id');
        });
        Schema::dropIfExists('project_deliverables');
        Schema::dropIfExists('project_milestones');
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
        Schema::dropIfExists('projects');
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('delivery_template');
        });
    }
};

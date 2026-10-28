<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name', 150);
            $table->string('billing_frequency', 16);
            // How often a maintenance visit is scheduled.
            $table->string('service_interval', 16);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('checklist');
            $table->date('next_service_at')->nullable()->index();
            // End of the agreed term; renewal reminders count down to it.
            $table->date('term_ends_at')->index();
            $table->string('status', 16)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('status', 24)->default('scheduled')->index();
            $table->date('scheduled_at')->index();
            $table->json('checklist');
            $table->json('health_detail')->nullable();
            $table->text('notes')->nullable();
            $table->text('evidence')->nullable();
            // scheduler, user or agent
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Repeated scheduler runs cannot create a second visit for the same day.
            $table->unique(['maintenance_plan_id', 'scheduled_at']);
        });

        Schema::create('maintenance_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_task_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 16);
            $table->text('description');
            $table->text('recommendation')->nullable();
            // Fixing it means changing the live site, which needs the founder's approval.
            $table->boolean('risky');
            $table->string('status', 24)->default('open')->index();
            $table->text('change_plan')->nullable();
            $table->text('rollback_plan')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48)->index();
            $table->string('severity', 16);
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // The same condition raises one alert.
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('operating_reports', function (Blueprint $table) {
            $table->id();
            $table->string('period', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['period', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_reports');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('maintenance_findings');
        Schema::dropIfExists('maintenance_tasks');
        Schema::dropIfExists('maintenance_plans');
    }
};

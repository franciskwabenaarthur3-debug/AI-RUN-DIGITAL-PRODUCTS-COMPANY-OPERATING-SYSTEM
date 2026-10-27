<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            // Stored lower-cased; unique so duplicates are caught by the database too.
            $table->string('email')->nullable()->unique();
            $table->string('phone', 50)->nullable();
            $table->string('website', 2048)->nullable();
            // Normalized host (lower-case, no "www."), used for duplicate detection.
            $table->string('website_domain')->nullable()->unique();
            $table->string('source', 32);
            $table->text('source_reference')->nullable();
            $table->string('research_status', 16)->default('pending');
            $table->unsignedTinyInteger('qualification_score')->nullable();
            $table->string('status', 32)->default('new')->index();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('do_not_contact')->default(false)->index();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_out_reason', 500)->nullable();
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->string('activity_type', 50);
            $table->text('description');
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};

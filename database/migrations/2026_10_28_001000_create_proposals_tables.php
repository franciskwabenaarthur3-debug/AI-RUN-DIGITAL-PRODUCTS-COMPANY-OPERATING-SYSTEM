<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per version. Versions of the same proposal share a reference.
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 48)->index();
            $table->unsignedInteger('version');
            $table->foreignId('previous_version_id')->nullable()->constrained('proposals')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_package_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('introduction')->nullable();
            // Package scope, exclusions, delivery days and revisions at the time of drafting.
            $table->json('scope_snapshot');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->string('discount_reason', 500)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('payment_terms');
            $table->string('status', 24)->default('draft')->index();
            $table->date('expires_at');
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_method', 16)->nullable();
            $table->string('delivery_status', 32)->nullable();
            $table->string('delivery_reference')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reference', 'version']);
            // A version can be superseded only once.
            $table->unique('previous_version_id');
        });

        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 300);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('proposal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['proposal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_events');
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('proposals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Last time the scheduler and the queue worker were seen working.
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->timestamp('last_seen_at');
            $table->json('detail')->nullable();
            $table->timestamps();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('driver', 16);
            $table->string('status', 16)->index(); // running, succeeded, failed
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('table_counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('verify_status', 16)->nullable(); // passed, failed
            $table->json('verify_detail')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::dropIfExists('system_heartbeats');
    }
};

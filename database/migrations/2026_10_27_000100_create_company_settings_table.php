<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->char('default_currency', 3)->default('USD');
            $table->string('timezone', 64)->default('UTC');
            $table->string('approval_policy', 32)->default('strict');
            // Money is stored in integer minor units of default_currency.
            $table->bigInteger('monthly_ai_budget_minor')->default(0);
            $table->string('operating_status', 16)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};

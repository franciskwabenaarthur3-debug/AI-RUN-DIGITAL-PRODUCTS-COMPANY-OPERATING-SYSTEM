<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_usage', function (Blueprint $table) {
            $table->string('provider_request_id', 128)->nullable()->after('model');
            $table->unsignedInteger('web_search_requests')->default(0)->after('output_tokens');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            // Spend share of the monthly AI budget that raises a warning.
            $table->unsignedTinyInteger('ai_budget_alert_percent')->default(80)->after('monthly_ai_budget_minor');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('ai_budget_alert_percent');
        });

        Schema::table('model_usage', function (Blueprint $table) {
            $table->dropColumn(['provider_request_id', 'web_search_requests']);
        });
    }
};

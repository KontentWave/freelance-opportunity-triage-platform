<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('demo_ai_deliveries', function (Blueprint $table) {
            $table->ulid('run_id')->primary();
            $table->foreign('run_id')->references('id')->on('demo_ai_runs')->restrictOnDelete();
            $table->char('report_sha256', 64);
            $table->char('recipient_identity', 64);
            $table->string('recipient_config_version', 64);
            $table->char('verification_reference_sha256', 64);
            $table->string('status', 20);
            $table->string('failure_code', 80)->nullable();
            $table->char('html_sha256', 64)->nullable();
            $table->timestamp('prepared_at');
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('receipt_reported_at')->nullable();
            $table->char('receipt_reference_sha256', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_ai_deliveries');
    }
};

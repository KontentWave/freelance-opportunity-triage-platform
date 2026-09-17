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
        Schema::create('opportunity_enrichments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('evaluation_id')->constrained('opportunity_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->text('full_description');
            $table->json('overrides');
            $table->json('input_snapshot');
            $table->json('result');
            $table->char('payload_sha256', 64);
            $table->char('input_sha256', 64);
            $table->timestamp('created_at');

            $table->unique(['evaluation_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunity_enrichments');
    }
};

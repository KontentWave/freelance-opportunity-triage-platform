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
        Schema::create('opportunity_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('evaluation_id')->constrained('opportunity_evaluations')->cascadeOnDelete();
            $table->string('human_label', 8);
            $table->string('reason_code', 32)->nullable();
            $table->string('sample_kind', 8);
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique('evaluation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunity_reviews');
    }
};

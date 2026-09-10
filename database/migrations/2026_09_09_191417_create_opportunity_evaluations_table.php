<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opportunity_evaluations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('engine_version', 32);
            $table->char('profile_version', 64);
            $table->char('input_sha256', 64);
            $table->json('profile_snapshot');
            $table->json('input_snapshot');
            $table->json('result');
            $table->string('recommendation', 8);
            $table->unsignedTinyInteger('score');
            $table->timestamp('created_at');

            $table->unique(
                ['workspace_id', 'opportunity_id', 'engine_version', 'profile_version', 'input_sha256'],
                'opportunity_evaluation_identity_unique',
            );
        });

        DB::statement(
            'ALTER TABLE opportunity_evaluations ADD CONSTRAINT opportunity_evaluations_score_check CHECK (score BETWEEN 0 AND 100)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunity_evaluations');
    }
};

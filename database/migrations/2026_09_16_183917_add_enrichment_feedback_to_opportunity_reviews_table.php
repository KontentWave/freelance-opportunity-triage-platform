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
        Schema::table('opportunity_reviews', function (Blueprint $table) {
            $table->foreignUlid('enrichment_id')
                ->nullable()
                ->after('evaluation_id')
                ->constrained('opportunity_enrichments')
                ->nullOnDelete();
            $table->text('notes')->nullable()->after('reason_code');
            $table->string('outcome', 16)->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opportunity_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrichment_id');
            $table->dropColumn(['notes', 'outcome']);
        });
    }
};

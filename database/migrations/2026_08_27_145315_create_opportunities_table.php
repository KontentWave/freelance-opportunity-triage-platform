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
        Schema::create('opportunities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id', 64);
            $table->string('canonical_url', 500);
            $table->string('title', 255);
            $table->string('contract_type', 32);
            $table->decimal('hourly_min', 10, 2)->nullable();
            $table->decimal('hourly_max', 10, 2)->nullable();
            $table->char('currency', 3);
            $table->string('estimated_duration', 100)->nullable();
            $table->date('posted_on')->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedSmallInteger('hidden_skill_count')->default(0);
            $table->boolean('payment_verified')->nullable();
            $table->decimal('client_rating', 3, 2)->nullable();
            $table->decimal('client_spend_usd', 14, 2)->nullable();
            $table->boolean('client_spend_approximate')->default(false);
            $table->string('client_country', 100)->nullable();
            $table->string('source_template', 64);
            $table->timestamps();

            $table->unique(['workspace_id', 'provider', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};

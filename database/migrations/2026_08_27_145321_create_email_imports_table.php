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
        Schema::create('email_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_id', 255)->nullable();
            $table->char('content_sha256', 64);
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'message_id']);
            $table->unique(['workspace_id', 'content_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_imports');
    }
};

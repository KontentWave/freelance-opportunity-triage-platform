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
        Schema::create('mailbox_checkpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('mailbox_key', 64);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_discovered_uid')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'mailbox_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_checkpoints');
    }
};

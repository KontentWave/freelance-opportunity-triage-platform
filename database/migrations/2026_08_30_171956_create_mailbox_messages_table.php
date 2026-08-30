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
        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mailbox_key', 64);
            $table->unsignedBigInteger('uid_validity');
            $table->unsignedBigInteger('message_uid');
            $table->string('status', 32);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'mailbox_key', 'uid_validity', 'message_uid'],
                'mailbox_message_uid_unique',
            );
            $table->index(
                ['workspace_id', 'mailbox_key', 'status', 'next_attempt_at'],
                'mailbox_message_retry_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};

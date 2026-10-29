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
        Schema::create('demo_ai_budgets', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedBigInteger('reserved_micro_usd')->default(0);
            $table->unsignedBigInteger('settled_micro_usd')->default(0);
        });
        Schema::create('demo_ai_control', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('fence')->default(0);
            $table->ulid('attempt_id')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->boolean('blocked')->default(false);
            $table->timestamp('last_dispatch_at', 6)->nullable();
        });
        DB::table('demo_ai_control')->insert(['id' => 1]);
        Schema::create('demo_ai_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->restrictOnDelete();
            $table->char('session_hash', 64);
            $table->char('ip_hash', 64);
            $table->char('idempotency_hash', 64)->unique();
            $table->enum('status', ['queued', 'running', 'completed', 'partial', 'failed', 'rejected']);
            $table->date('reservation_day');
            $table->unsignedBigInteger('reserved_micro_usd');
            $table->json('approved_snapshot')->nullable();
            $table->char('approved_snapshot_sha256', 64);
            $table->char('version_hash', 64);
            $table->json('source_snapshot')->nullable();
            $table->char('source_snapshot_sha256', 64)->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('admitted_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->ulid('discovery_token')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedSmallInteger('selected_count')->default(0);
            $table->unsignedSmallInteger('succeeded_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'admitted_at']);
            $table->index(['session_hash', 'admitted_at']);
            $table->index(['ip_hash', 'admitted_at']);
            $table->unique(['id', 'workspace_id']);
        });
        Schema::create('demo_ai_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->foreignUlid('workspace_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('ordinal');
            $table->string('source_namespace', 64);
            $table->string('source_id', 32);
            $table->string('mailbox_key', 100);
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->char('body_sha256', 64);
            $table->char('input_sha256', 64);
            $table->char('raw_sha256', 64);
            $table->json('input_snapshot')->nullable();
            $table->json('result')->nullable();
            $table->enum('status', ['pending', 'in_flight', 'retry_wait', 'succeeded', 'failed', 'uncertain', 'expired']);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('fence')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'source_namespace', 'source_id'], 'demo_ai_item_source_unique');
            $table->unique(['run_id', 'ordinal']);
            $table->foreign(['run_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('demo_ai_runs')->restrictOnDelete();
        });
        Schema::create('demo_ai_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained('demo_ai_items')->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->date('dispatch_day');
            $table->enum('status', ['in_flight', 'known', 'uncertain']);
            $table->unsignedBigInteger('fence');
            $table->unsignedBigInteger('reserved_micro_usd');
            $table->unsignedBigInteger('settled_micro_usd')->nullable();
            $table->json('pricing_snapshot');
            $table->json('request_provenance');
            $table->json('usage')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('returned_model')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('dispatched_at', 6);
            $table->timestamp('inference_authorized_at', 6)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'attempt_number']);
            $table->index('dispatched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_ai_attempts');
        Schema::dropIfExists('demo_ai_items');
        Schema::dropIfExists('demo_ai_runs');
        Schema::dropIfExists('demo_ai_control');
        Schema::dropIfExists('demo_ai_budgets');
    }
};

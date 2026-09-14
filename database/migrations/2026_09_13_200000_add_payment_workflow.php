<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('stores')->insert(['id' => 1, 'name' => 'Demo Store', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['users', 'products', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('store_id')->default(1)->constrained()->restrictOnDelete();
            });
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['store_id', 'status']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('scenario', 40)->default('success');
            $table->string('operation', 20)->default('charge');
            $table->uuid('request_id')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_expires_at')->nullable()->index();
            $table->timestamp('refunded_at')->nullable();
        });
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->timestamp('available_at')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'aggregate_id']);
            $table->index(['store_id', 'published_at', 'available_at']);
        });
        // This ledger simulates the provider's durable state, independently of order transactions.
        Schema::create('mock_transactions', function (Blueprint $table) {
            $table->uuid('idempotency_key')->primary();
            $table->string('status', 20);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_transactions');
        Schema::dropIfExists('outbox_messages');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn([
            'scenario', 'operation', 'request_id', 'processing_token', 'processing_expires_at', 'refunded_at',
        ]));
        foreach (['orders', 'products', 'users'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['store_id']));
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropIndex(['store_id', 'status']);
            $table->dropColumn(['idempotency_key', 'request_hash', 'cancellation_requested_at']);
        });
        foreach (['orders', 'products', 'users'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('store_id'));
        }
        Schema::dropIfExists('stores');
    }
};

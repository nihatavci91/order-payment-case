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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();
            $table->uuid('payment_number')->unique();
            $table->string('status', 50)
                ->default('pending');
            $table->string('provider', 50)
                ->default('mock');
            $table->string('provider_reference')
                ->nullable()
                ->index();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TRY');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code')
                ->nullable();
            $table->text('last_error_message')
                ->nullable();
            $table->timestamp('next_retry_at')
                ->nullable()
                ->index();
            $table->timestamp('paid_at')
                ->nullable();
            $table->timestamp('failed_at')
                ->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

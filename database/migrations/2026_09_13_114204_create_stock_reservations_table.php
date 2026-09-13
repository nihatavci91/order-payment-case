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
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 30)
                ->default('reserved');
            $table->timestamp('expires_at')
                ->index();
            $table->timestamp('released_at')
                ->nullable();
            $table->timestamp('consumed_at')
                ->nullable();
            $table->timestamps();
            $table->unique([
                'order_id',
                'product_id',
            ]);
            $table->index([
                'status',
                'expires_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};

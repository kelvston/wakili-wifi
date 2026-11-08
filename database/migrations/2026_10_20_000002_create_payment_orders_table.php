<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider_order_id')->nullable()->unique();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete()->unique();
            $table->string('package_name');
            $table->string('phone', 20);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('TZS');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('package_name');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('TZS');
            $table->string('status')->default('available');
            $table->string('assigned_to_phone')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('used_by_phone')->nullable();
            $table->timestamps();

            $table->index(['status', 'package_name']);
            $table->index(['assigned_to_phone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

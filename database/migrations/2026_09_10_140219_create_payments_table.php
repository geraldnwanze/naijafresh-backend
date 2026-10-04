<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('mock');
            $table->string('method');
            $table->string('status')->default('pending');
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('amount_kobo');
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable();
            $table->text('authorization_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

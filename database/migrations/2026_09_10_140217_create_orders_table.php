<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');

            $table->string('contact_first_name');
            $table->string('contact_last_name');
            $table->string('contact_phone');
            $table->string('contact_email');

            $table->string('delivery_street');
            $table->string('delivery_area');
            $table->string('delivery_city');
            $table->string('delivery_state')->nullable();
            $table->string('delivery_country')->default('Nigeria');
            $table->text('delivery_notes')->nullable();

            $table->foreignId('delivery_window_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_window_label')->nullable();
            $table->string('delivery_window_time')->nullable();
            $table->date('delivery_date')->nullable();

            $table->string('payment_method');
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('subtotal_kobo');
            $table->unsignedInteger('delivery_fee_kobo')->default(0);
            $table->unsignedInteger('discount_kobo')->default(0);
            $table->unsignedInteger('total_kobo');

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

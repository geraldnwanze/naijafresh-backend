<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('type')->default('ingredient');
            // Snapshot of how the item was sold. For 'weight', quantity is grams
            // and unit_price_kobo is the price per kg.
            $table->string('sold_by')->default('unit');
            $table->string('storage_type')->default('ambient');
            $table->string('unit');
            $table->string('variant_name')->nullable();
            $table->string('image_url')->nullable();

            $table->unsignedInteger('unit_price_kobo');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total_kobo');

            // Cost snapshot at the time of sale so profit history survives later
            // cost changes. Null when the product had no cost price.
            $table->unsignedInteger('unit_cost_kobo')->nullable();
            $table->unsignedInteger('line_cost_kobo')->nullable();

            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

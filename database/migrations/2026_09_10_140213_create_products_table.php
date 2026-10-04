<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('ingredient');
            // 'weight': price_kobo is per kg and quantities/stock are integer grams.
            $table->string('sold_by')->default('unit');
            $table->string('storage_type')->default('ambient');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->unsignedInteger('price_kobo');
            $table->unsignedInteger('compare_at_price_kobo')->nullable();
            // What the product costs us, in the same unit as price_kobo (per item,
            // or per kg for weight-sold products). Null = not entered yet.
            $table->unsignedInteger('cost_price_kobo')->nullable();
            $table->string('unit');
            // Weight-based selling (grams); null for items sold per unit.
            $table->unsignedInteger('min_weight_grams')->nullable();
            $table->unsignedInteger('weight_step_grams')->nullable();
            $table->unsignedInteger('max_weight_grams')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_available')->default(true);
            $table->string('preparation_type')->nullable();
            $table->string('image_url')->nullable();
            $table->jsonb('tags')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('position')->default(0);

            // Meal-kit specific fields (null for plain ingredients).
            $table->string('serves')->nullable();
            $table->unsignedInteger('prep_time_minutes')->nullable();
            $table->jsonb('included_items')->nullable();
            $table->jsonb('not_included_items')->nullable();
            $table->text('storage_instructions')->nullable();
            $table->text('cooking_instructions')->nullable();

            $table->timestamps();

            $table->index('category_id');
            $table->index(['type', 'is_available']);
            $table->index(['storage_type', 'is_available']);
            $table->index(['is_featured', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

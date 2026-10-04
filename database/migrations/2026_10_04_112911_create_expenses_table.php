<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('category');
            $table->string('description');
            $table->unsignedInteger('amount_kobo');
            // The business day the cost belongs to (drives the P&L period).
            $table->date('incurred_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('incurred_on');
            $table->index(['category', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

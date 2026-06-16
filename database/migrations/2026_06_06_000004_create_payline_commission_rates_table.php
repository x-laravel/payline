<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payline_commission_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('gateway', 50);
            $table->string('card_family', 50)->nullable();
            $table->string('card_type', 30)->nullable();
            $table->unsignedTinyInteger('installments')->default(1);
            $table->decimal('rate', 8, 4);
            $table->unsignedTinyInteger('blocking_days')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(
                ['gateway', 'card_family', 'card_type', 'installments'],
                'payline_commission_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payline_commission_rates');
    }
};
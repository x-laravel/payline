<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('payline.database.connection');
    }

    public function up(): void
    {
        Schema::create('payline_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('gateway', 50);
            $table->string('initial_type', 30);
            $table->string('status', 30)->default('initiated');
            $table->string('idempotency_key')->nullable();
            $table->char('request_hash', 64);

            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TRY');

            $table->char('card_bin', 8)->nullable();
            $table->char('card_last_four', 4)->nullable();
            $table->string('card_holder_name')->nullable();

            $table->nullableMorphs('payable');
            $table->nullableMorphs('owner');

            $table->string('reference')->nullable()->index();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'status']);
            $table->unique(['gateway', 'initial_type', 'idempotency_key']);
            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index(['owner_type', 'owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payline_payments');
    }
};

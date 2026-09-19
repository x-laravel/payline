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
        Schema::create('payline_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('payment_id')
                ->constrained('payline_payments')
                ->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('status', 30)->default('initiated');

            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TRY');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('idempotency_key')->nullable();
            $table->char('request_hash', 64);

            $table->string('gateway_transaction_id')->nullable()->index();
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_auth_code', 100)->nullable();
            $table->string('gateway_response_code', 50)->nullable();
            $table->text('gateway_response_message')->nullable();

            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->text('redirect_url')->nullable();

            $table->json('metadata')->nullable();

            $table->ulid('parent_transaction_id')->nullable();
            $table->foreign('parent_transaction_id')
                ->references('id')
                ->on('payline_transactions')
                ->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'type', 'status']);
            $table->unique(['payment_id', 'type', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payline_transactions');
    }
};

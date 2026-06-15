<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payline_webhook_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('gateway', 50);
            $table->string('event_type')->nullable();
            $table->string('gateway_event_id')->nullable();
            $table->json('payload');
            $table->string('status', 30)->default('received');
            $table->text('exception')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'status']);
            $table->index(['gateway', 'gateway_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payline_webhook_logs');
    }
};
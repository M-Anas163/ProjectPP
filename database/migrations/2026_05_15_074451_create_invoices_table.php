<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('invoice_number')->unique();

            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'generated', 'sent', 'failed'])
                  ->default('pending');

            $table->string('idempotency_key')->unique();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

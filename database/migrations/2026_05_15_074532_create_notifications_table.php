<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->enum('type', [
                'order_confirmed',
                'invoice_ready',
                'warehouse_notified',
                'order_failed'
            ]);

            $table->enum('channel', ['email', 'sms', 'push'])->default('email');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');

            $table->string('idempotency_key')->unique();

            $table->text('payload')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending');

            $table->decimal('total_amount', 10, 2)->default(0);

            $table->enum('processing_mode', ['unsafe', 'safe'])->default('unsafe');

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

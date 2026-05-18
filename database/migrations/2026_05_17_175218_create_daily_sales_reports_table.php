<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();

            $table->unsignedInteger('total_orders');
            $table->unsignedInteger('completed_orders');
            $table->unsignedInteger('failed_orders');
            $table->decimal('total_revenue', 12, 2);
            $table->decimal('avg_order_value', 10, 2);

            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('processed_chunks')->default(0);

            $table->unsignedInteger('last_processed_chunk')->default(0);

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'partial'
            ])->default('pending');

            $table->float('processing_time_seconds')->nullable();
            $table->float('records_per_second')->nullable();

            $table->string('batch_id')->unique();

            $table->timestamps();
        });

        Schema::create('batch_failed_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->date('report_date');
            $table->unsignedInteger('chunk_number');
            $table->text('failure_reason');
            $table->boolean('reprocessed')->default(false);
            $table->timestamps();
        });

        Schema::create('batch_chunk_logs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id');
            $table->unsignedInteger('chunk_number');
            $table->unsignedInteger('offset');
            $table->unsignedInteger('limit');    
            $table->unsignedInteger('records_processed')->default(0);
            $table->float('chunk_time_seconds')->nullable();
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])
                  ->default('pending');
            $table->timestamps();

            $table->unique(['batch_id', 'chunk_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_chunk_logs');
        Schema::dropIfExists('batch_failed_orders');
        Schema::dropIfExists('daily_sales_reports');
    }
};

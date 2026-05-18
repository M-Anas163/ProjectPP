<?php

namespace App\Jobs;

use App\Models\BatchChunkLog;
use App\Models\DailySalesReport;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSalesChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        private readonly string $batchId,
        private readonly int    $chunkNumber,
        private readonly int    $offset,
        private readonly int    $chunkSize,
        private readonly string $reportDate
    ) {}

    public function handle(): void
    {
        $chunkStart = microtime(true);


        $chunkLog = BatchChunkLog::where('batch_id', $this->batchId)
            ->where('chunk_number', $this->chunkNumber)
            ->first();

        if ($chunkLog && $chunkLog->status === 'done') {
            Log::info("CHUNK ALREADY PROCESSED — SKIPPING (Checkpoint)", [
                'batch_id'     => $this->batchId,
                'chunk_number' => $this->chunkNumber,
            ]);
            return;
        }

        BatchChunkLog::updateOrCreate(
            [
                'batch_id'     => $this->batchId,
                'chunk_number' => $this->chunkNumber,
            ],
            [
                'offset' => $this->offset,
                'limit'  => $this->chunkSize,
                'status' => 'processing',
            ]
        );

        Log::info("PROCESSING CHUNK", [
            'batch_id'     => $this->batchId,
            'chunk'        => $this->chunkNumber,
            'offset'       => $this->offset,
            'size'         => $this->chunkSize,
            'worker_pid'   => getmypid(),
        ]);


        $orders = Order::with('items')
            ->whereDate('created_at', $this->reportDate)
            ->whereIn('status', ['completed', 'failed'])
            ->orderBy('id')
            ->offset($this->offset)
            ->limit($this->chunkSize)
            ->get();

        $chunkRevenue   = 0;
        $completedCount = 0;
        $failedCount    = 0;
        $processedCount = 0;

        foreach ($orders as $order) {
            try {

                if ($order->status === 'completed') {
                    $chunkRevenue += $order->total_amount;
                    $completedCount++;
                } else {
                    $failedCount++;
                }

                $processedCount++;

            } catch (\Exception $e) {

                Log::warning("CHUNK: Order processing failed — SKIPPING", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);

                DB::table('batch_failed_orders')->insert([
                    'order_id'      => $order->id,
                    'report_date'   => $this->reportDate,
                    'chunk_number'  => $this->chunkNumber,
                    'failure_reason'=> $e->getMessage(),
                ]);
            }
        }

        $chunkTimeSeconds = round(microtime(true) - $chunkStart, 3);


        DB::transaction(function () use (
            $chunkRevenue, $completedCount, $failedCount,
            $processedCount, $chunkTimeSeconds
        ) {
            // تحديث سجل الـ Chunk
            BatchChunkLog::where('batch_id', $this->batchId)
                ->where('chunk_number', $this->chunkNumber)
                ->update([
                    'records_processed' => $processedCount,
                    'chunk_time_seconds'=> $chunkTimeSeconds,
                    'status'            => 'done',
                ]);


            DB::table('daily_sales_reports')
                ->where('batch_id', $this->batchId)
                ->update([
                    'processed_chunks' => DB::raw('processed_chunks + 1'),
                    'completed_orders'  => DB::raw('completed_orders + ' . (int) $completedCount),
                    'failed_orders'     => DB::raw('failed_orders + ' . (int) $failedCount),
                    'total_revenue'     => DB::raw('total_revenue + ' . (float) $chunkRevenue),
                ]);

            DB::table('daily_sales_reports')
                ->where('batch_id', $this->batchId)
                ->update([
                    'last_processed_chunk' => DB::raw(
                        "GREATEST(last_processed_chunk, {$this->chunkNumber})"
                    ),
                ]);
        });

        Log::info("CHUNK COMPLETED", [
            'batch_id'           => $this->batchId,
            'chunk'              => $this->chunkNumber,
            'processed'          => $processedCount,
            'revenue'            => $chunkRevenue,
            'time_seconds'       => $chunkTimeSeconds,
            'records_per_second' => $processedCount > 0
                ? round($processedCount / $chunkTimeSeconds, 1)
                : 0,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("CHUNK PERMANENTLY FAILED → Dead Letter", [
            'batch_id'     => $this->batchId,
            'chunk_number' => $this->chunkNumber,
            'error'        => $exception->getMessage(),
        ]);

        BatchChunkLog::where('batch_id', $this->batchId)
            ->where('chunk_number', $this->chunkNumber)
            ->update(['status' => 'failed']);
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSalesChunkJob;
use App\Models\DailySalesReport;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessDailySalesCommand extends Command
{
    protected $signature = 'sales:process-daily
                           {--date=      : تاريخ الجرد (افتراضي: أمس)}
                           {--chunk=500  : حجم الـ Chunk}
                           {--no-parallel: معالجة تسلسلية بدون NFR للمقارنة}';

    protected $description = 'جرد المبيعات اليومية ومعالجتها كـ Chunks متوازية';

    public function handle(): int
    {
        $date      = $this->option('date') ?? now()->subDay()->toDateString();
        $chunkSize = (int) $this->option('chunk');
        $parallel  = !$this->option('no-parallel');

        $this->info("════════════════════════════════════════");
        $this->info("  جرد مبيعات يوم: {$date}");
        $this->info("  الوضع: " . ($parallel ? "★ Parallel Batch (NFR4)" : "Sequential (بدون NFR)"));
        $this->info("  حجم الـ Chunk: {$chunkSize} طلب");
        $this->info("════════════════════════════════════════");

        $totalOrders = Order::whereDate('created_at', $date)
            ->whereIn('status', ['completed', 'failed'])
            ->count();

        if ($totalOrders === 0) {
            $this->warn("لا توجد طلبات في هذا اليوم: {$date}");
            return self::SUCCESS;
        }

        $totalChunks = (int) ceil($totalOrders / $chunkSize);
        $batchId     = "batch_{$date}_" . Str::random(8);

        $this->info("إجمالي الطلبات: {$totalOrders}");
        $this->info("عدد الـ Chunks:  {$totalChunks}");
        $this->info("Batch ID:        {$batchId}");
        $this->line("");


        $existingReport = DailySalesReport::where('report_date', $date)
            ->where('status', 'completed')
            ->first();

        if ($existingReport) {
            $this->warn("يوجد تقرير مكتمل لهذا اليوم بالفعل!");
            $this->info("Batch ID السابق: {$existingReport->batch_id}");
            $this->info("استخدم --date لتاريخ آخر أو احذف التقرير القديم");
            return self::SUCCESS;
        }

        $report = DailySalesReport::create([
            'report_date'          => $date,
            'total_orders'         => $totalOrders,
            'completed_orders'     => 0,
            'failed_orders'        => 0,
            'total_revenue'        => 0,
            'avg_order_value'      => 0,
            'total_chunks'         => $totalChunks,
            'chunk_size'           => $chunkSize,
            'processed_chunks'     => 0,
            'last_processed_chunk' => 0,
            'status'               => 'processing',
            'batch_id'             => $batchId,
        ]);

        $batchStart = microtime(true);

        if ($parallel) {
            $this->runParallelBatch($batchId, $date, $chunkSize, $totalChunks);
        } else {
            $this->runSequentialBatch($batchId, $date, $chunkSize, $totalChunks, $report);
        }

        $totalTime = round(microtime(true) - $batchStart, 2);

        $report->refresh();
        $avgOrderValue = $report->completed_orders > 0
            ? round($report->total_revenue / $report->completed_orders, 2)
            : 0;

        $recordsPerSecond = $totalOrders > 0
            ? round($totalOrders / $totalTime, 1)
            : 0;

        $report->update([
            'avg_order_value'        => $avgOrderValue,
            'processing_time_seconds'=> $totalTime,
            'records_per_second'     => $recordsPerSecond,
            'status'                 => $parallel ? 'completed' : 'completed',
        ]);

        $this->showResults($report->fresh(), $totalTime, $recordsPerSecond, $parallel);

        return self::SUCCESS;
    }


    private function runParallelBatch(
        string $batchId,
        string $date,
        int    $chunkSize,
        int    $totalChunks
    ): void {
        $this->info("★ إطلاق {$totalChunks} Chunks للـ Queue بشكل متوازٍ...");

        $progressBar = $this->output->createProgressBar($totalChunks);
        $progressBar->start();

        for ($chunkNumber = 1; $chunkNumber <= $totalChunks; $chunkNumber++) {
            $offset = ($chunkNumber - 1) * $chunkSize;

            ProcessSalesChunkJob::dispatch(
                $batchId,
                $chunkNumber,
                $offset,
                $chunkSize,
                $date
            )->onQueue('batch');

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line("");
        $this->info("✓ جميع الـ Chunks أُرسلت للـ Queue");
        $this->info("  Workers تعالجها الآن بالتوازي...");
        $this->info("  راقب التقدم: GET /api/batch/status/{$batchId}");
    }


    private function runSequentialBatch(
        string          $batchId,
        string          $date,
        int             $chunkSize,
        int             $totalChunks,
        DailySalesReport $report
    ): void {
        $this->warn("⚠ وضع Sequential (بدون NFR4) — أبطأ بكثير");

        $progressBar = $this->output->createProgressBar($totalChunks);
        $progressBar->start();

        $totalRevenue   = 0;
        $completedCount = 0;
        $failedCount    = 0;

        for ($chunkNumber = 1; $chunkNumber <= $totalChunks; $chunkNumber++) {
            $offset = ($chunkNumber - 1) * $chunkSize;

            $orders = Order::whereDate('created_at', $date)
                ->whereIn('status', ['completed', 'failed'])
                ->orderBy('id')
                ->offset($offset)
                ->limit($chunkSize)
                ->get();

            foreach ($orders as $order) {
                if ($order->status === 'completed') {
                    $totalRevenue += $order->total_amount;
                    $completedCount++;
                } else {
                    $failedCount++;
                }
            }

            $report->update([
                'processed_chunks'     => $chunkNumber,
                'last_processed_chunk' => $chunkNumber,
                'completed_orders'     => $completedCount,
                'failed_orders'        => $failedCount,
                'total_revenue'        => $totalRevenue,
            ]);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line("");
    }

    private function showResults(
        DailySalesReport $report,
        float $totalTime,
        float $recordsPerSecond,
        bool  $parallel
    ): void {
        $this->line("");
        $this->info("════════════════════════════════════════");
        $this->info("         نتائج الجرد اليومي            ");
        $this->info("════════════════════════════════════════");
        $this->table(
            ['المقياس', 'القيمة'],
            [
                ['الوضع',            $parallel ? '★ Parallel Batch' : 'Sequential'],
                ['إجمالي الطلبات',   $report->total_orders],
                ['الطلبات المكتملة', $report->completed_orders],
                ['الطلبات الفاشلة',  $report->failed_orders],
                ['إجمالي الإيرادات', number_format($report->total_revenue, 2) . ' ر.س'],
                ['متوسط الطلب',      number_format($report->avg_order_value, 2) . ' ر.س'],
                ['عدد الـ Chunks',   $report->total_chunks],
                ['حجم كل Chunk',     $report->chunk_size . ' طلب'],
                ['زمن المعالجة',     $totalTime . ' ثانية'],
                ['السرعة',           $recordsPerSecond . ' طلب/ثانية'],
            ]
        );
        $this->info("════════════════════════════════════════");
    }
}

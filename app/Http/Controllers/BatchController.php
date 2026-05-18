<?php

namespace App\Http\Controllers;

use App\Models\DailySalesReport;
use App\Models\BatchChunkLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class BatchController extends Controller
{

    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'date'       => 'nullable|date',
            'chunk_size' => 'nullable|integer|min:10|max:5000',
            'mode'       => 'nullable|in:parallel,sequential',
        ]);

        $date      = $request->input('date', now()->subDay()->toDateString());
        $chunkSize = $request->input('chunk_size', 500);
        $parallel  = $request->input('mode', 'parallel') === 'parallel';

        $options = ["--date" => $date, "--chunk" => $chunkSize];
        if (!$parallel) {
            $options['--no-parallel'] = true;
        }

        Artisan::queue('sales:process-daily', $options);

        return response()->json([
            'success'    => true,
            'message'    => 'بدأ الجرد اليومي',
            'date'       => $date,
            'chunk_size' => $chunkSize,
            'mode'       => $parallel ? 'parallel (NFR4)' : 'sequential',
            'track_url'  => "/api/batch/reports/{$date}",
        ], 202);
    }


    public function report(string $date): JsonResponse
    {
        $report = DailySalesReport::where('report_date', $date)->first();

        if (!$report) {
            return response()->json([
                'message' => 'لا يوجد تقرير لهذا اليوم',
                'date'    => $date,
            ], 404);
        }

        $chunks = BatchChunkLog::where('batch_id', $report->batch_id)
            ->orderBy('chunk_number')
            ->get(['chunk_number', 'records_processed', 'chunk_time_seconds', 'status']);

        return response()->json([
            'report'   => [
                'date'               => $report->report_date,
                'status'             => $report->status,
                'batch_id'           => $report->batch_id,
                'total_orders'       => $report->total_orders,
                'completed_orders'   => $report->completed_orders,
                'failed_orders'      => $report->failed_orders,
                'total_revenue'      => $report->total_revenue,
                'avg_order_value'    => $report->avg_order_value,
                'progress'           => [
                    'total_chunks'     => $report->total_chunks,
                    'processed_chunks' => $report->processed_chunks,
                    'percentage'       => $report->progress_percentage . '%',
                ],
                'performance'        => [
                    'time_seconds'      => $report->processing_time_seconds,
                    'records_per_second'=> $report->records_per_second,
                ],
            ],
            'chunks'   => $chunks,
        ]);
    }

   
    public function comparison(): JsonResponse
    {
        $reports = DailySalesReport::selectRaw("
            report_date,
            total_orders,
            processing_time_seconds,
            records_per_second,
            total_chunks,
            chunk_size,
            status
        ")
        ->whereNotNull('processing_time_seconds')
        ->orderBy('report_date', 'desc')
        ->limit(10)
        ->get();

        return response()->json([
            'reports'     => $reports,
            'explanation' => [
                'parallel'   => 'NFR4 — Chunks × Workers المتوازية',
                'sequential' => 'بدون NFR4 — Chunk تلو الآخر',
                'metric'     => 'records_per_second يُثبت الفرق',
            ],
        ]);
    }
}

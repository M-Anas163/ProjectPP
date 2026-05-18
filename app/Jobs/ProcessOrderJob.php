<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\SafeOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public int $tries   = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        private readonly int   $userId,
        private readonly array $items,
        private readonly int   $orderId,
        private readonly float $submittedAt
    ) {}

    public function handle(SafeOrderService $orderService): void
    {
        $waitTimeMs = round((microtime(true) - $this->submittedAt) * 1000, 2);

        Log::info("JOB STARTED BY WORKER", [
            'order_id' => $this->orderId,
            'wait_ms' => $waitTimeMs,
            'attempt' => $this->attempts(),
            'worker_pid' => getmypid(),
        ]);

        try {

            $result = $orderService->processOrderForWorker(
                $this->userId,
                $this->items,
                $this->orderId
            );

            Log::info("JOB COMPLETED", [
                'order_id'      => $this->orderId,
                'processing_ms' => $result['processing_ms'],
                'wait_ms'       => $waitTimeMs,
                'worker_pid'    => getmypid(),
            ]);

        } catch (\Exception $e) {
            Log::error("JOB ATTEMPT FAILED", [
                'order_id' => $this->orderId,
                'attempt'  => $this->attempts(),
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

   
    public function failed(\Throwable $exception): void
    {
        Log::critical("JOB PERMANENTLY FAILED", [
            'order_id' => $this->orderId,
            'error'    => $exception->getMessage(),
        ]);

        Order::where('id', $this->orderId)
            ->update(['status' => 'failed']);
    }
}

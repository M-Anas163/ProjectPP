<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public int $tries   = 3;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(
        private readonly int    $orderId,
        private readonly int    $userId,
        private readonly float  $amount,
        private readonly string $idempotencyKey
    ) {}

    public function handle(): void
    {

        $existingInvoice = Invoice::where('idempotency_key', $this->idempotencyKey)
            ->where('status', 'generated')
            ->first();

        if ($existingInvoice) {
            Log::info("INVOICE ALREADY EXISTS — SKIPPING (Idempotency)", [
                'invoice_id'      => $existingInvoice->id,
                'idempotency_key' => $this->idempotencyKey,
            ]);
            return;
        }

        Log::info("GENERATING INVOICE IN BACKGROUND", [
            'order_id'  => $this->orderId,
            'attempt'   => $this->attempts(),
            'worker_pid'=> getmypid(),
        ]);

        $startTime = microtime(true);


        sleep(2);

        $invoice = Invoice::updateOrCreate(
            ['idempotency_key' => $this->idempotencyKey],
            [
                'order_id'       => $this->orderId,
                'user_id'        => $this->userId,
                'invoice_number' => 'INV-' . date('Y') . '-' . str_pad($this->orderId, 6, '0', STR_PAD_LEFT),
                'amount'         => $this->amount,
                'status'         => 'generated',
                'queued_at'      => now()->subSeconds(2),
                'generated_at'   => now(),
            ]
        );

        $processingMs = round((microtime(true) - $startTime) * 1000, 2);

        Log::info("INVOICE GENERATED IN BACKGROUND", [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_id'       => $this->orderId,
            'processing_ms'  => $processingMs,
            'note'           => 'المستخدم لم ينتظر هذه العملية',
        ]);
    }

   
    public function failed(\Throwable $exception): void
    {
        Log::critical("INVOICE GENERATION PERMANENTLY FAILED → DLQ", [
            'order_id'        => $this->orderId,
            'idempotency_key' => $this->idempotencyKey,
            'error'           => $exception->getMessage(),
        ]);

        Invoice::where('idempotency_key', $this->idempotencyKey)
            ->update(['status' => 'failed']);
    }
}

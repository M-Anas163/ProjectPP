<?php

namespace App\Jobs;

use App\Models\OrderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 20;

    public function backoff(): array
    {
        return [5, 10, 20];
    }

    public function __construct(
        private readonly int    $orderId,
        private readonly int    $userId,
        private readonly string $type,
        private readonly string $channel,
        private readonly array  $payload,
        private readonly string $idempotencyKey
    ) {}

    public function handle(): void
    {
        $existing = OrderNotification::where('idempotency_key', $this->idempotencyKey)
            ->where('status', 'sent')
            ->first();

        if ($existing) {
            Log::info("NOTIFICATION ALREADY SENT — SKIPPING", [
                'idempotency_key' => $this->idempotencyKey,
            ]);
            return;
        }

        $notification = OrderNotification::updateOrCreate(
            ['idempotency_key' => $this->idempotencyKey],
            [
                'order_id'   => $this->orderId,
                'user_id'    => $this->userId,
                'type'       => $this->type,
                'channel'    => $this->channel,
                'status'     => 'pending',
                'payload'    => json_encode($this->payload),
                'queued_at'  => now(),
                'attempts'   => $this->attempts(),
            ]
        );

        Log::info("SENDING NOTIFICATION IN BACKGROUND", [
            'type'       => $this->type,
            'channel'    => $this->channel,
            'order_id'   => $this->orderId,
            'worker_pid' => getmypid(),
        ]);

        sleep(1); 

        $notification->update([
            'status'   => 'sent',
            'sent_at'  => now(),
            'attempts' => $this->attempts(),
        ]);

        Log::info("NOTIFICATION SENT IN BACKGROUND", [
            'notification_id' => $notification->id,
            'type'            => $this->type,
            'order_id'        => $this->orderId,
            'note'            => 'المستخدم لم ينتظر هذا الإشعار',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("NOTIFICATION PERMANENTLY FAILED → DLQ", [
            'order_id' => $this->orderId,
            'type'     => $this->type,
            'error'    => $exception->getMessage(),
        ]);

        OrderNotification::where('idempotency_key', $this->idempotencyKey)
            ->update(['status' => 'failed']);
    }
}

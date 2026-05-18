<?php

namespace App\Services;

use App\Jobs\GenerateInvoiceJob;
use App\Jobs\SendOrderNotificationJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AsyncOrderService
{

    public function placeOrder(int $userId, array $items): array
    {
        $mainPathStart = microtime(true);

        $result = DB::transaction(function () use ($userId, $items) {

            $order       = Order::create([
                'user_id'         => $userId,
                'status'          => 'processing',
                'processing_mode' => 'async_nfr3',
                'queued_at'       => now(),
            ]);

            $totalAmount = 0;

            foreach ($items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw new \Exception("المنتج غير موجود: {$item['product_id']}");
                }

                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception(
                        "مخزون غير كافٍ: {$product->name} " .
                        "(متوفر: {$product->stock_quantity})"
                    );
                }

                $product->stock_quantity -= $item['quantity'];
                $product->save();

                $subtotal    = $product->price * $item['quantity'];
                $totalAmount += $subtotal;

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $subtotal,
                ]);
            }

            $order->update([
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'processed_at' => now(),
            ]);

            return [
                'order'       => $order,
                'totalAmount' => $totalAmount,
            ];
        });

        $order       = $result['order'];
        $totalAmount = $result['totalAmount'];

        $mainPathMs = round((microtime(true) - $mainPathStart) * 1000, 2);


        $this->dispatchBackgroundTasks($order->id, $userId, $totalAmount);

        Log::info("ORDER PLACED — MAIN PATH DONE", [
            'order_id'    => $order->id,
            'main_path_ms'=> $mainPathMs,
            'note'        => 'الفاتورة والإشعارات ستُعالَج في الخلفية',
        ]);

        return [
            'success'      => true,
            'order_id'     => $order->id,
            'total'        => $totalAmount,
            'status'       => 'completed',
            'response_ms'  => $mainPathMs,
            'message'      => 'تم قبول طلبك! الفاتورة ستصلك قريباً.',
            'track_invoice'=> "/api/orders/{$order->id}/invoice",
            'track_status' => "/api/orders/{$order->id}/status",
        ];
    }

    private function dispatchBackgroundTasks(
        int   $orderId,
        int   $userId,
        float $totalAmount
    ): void {

        GenerateInvoiceJob::dispatch(
            $orderId,
            $userId,
            $totalAmount,
            "invoice_{$orderId}"
        )->onQueue('notifications');

        SendOrderNotificationJob::dispatch(
            $orderId,
            $userId,
            'order_confirmed',
            'email',
            [
                'subject'  => "تأكيد طلبك رقم #{$orderId}",
                'order_id' => $orderId,
                'total'    => $totalAmount,
            ],
            "notify_confirm_{$orderId}"
        )->onQueue('notifications');

        SendOrderNotificationJob::dispatch(
            $orderId,
            $userId,
            'warehouse_notified',
            'email',
            [
                'subject'  => "طلب جديد للتجهيز رقم #{$orderId}",
                'order_id' => $orderId,
            ],
            "notify_warehouse_{$orderId}"
        )->onQueue('notifications')
         ->delay(now()->addSeconds(5));

        Log::info("BACKGROUND TASKS DISPATCHED", [
            'order_id' => $orderId,
            'jobs'     => ['GenerateInvoice', 'NotifyUser', 'NotifyWarehouse'],
            'queue'    => 'notifications',
        ]);
    }

    public function placeOrderSync(int $userId, array $items): array
    {
        $totalStart = microtime(true);

        $result = DB::transaction(function () use ($userId, $items) {
            $order       = Order::create([
                'user_id'         => $userId,
                'status'          => 'processing',
                'processing_mode' => 'sync_no_nfr3',
                'queued_at'       => now(),
            ]);
            $totalAmount = 0;

            foreach ($items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->lockForUpdate()->first();

                if (!$product || $product->stock_quantity < $item['quantity']) {
                    throw new \Exception("فشل المخزون");
                }

                $product->stock_quantity -= $item['quantity'];
                $product->save();

                $subtotal    = $product->price * $item['quantity'];
                $totalAmount += $subtotal;

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $subtotal,
                ]);
            }

            $order->update([
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'processed_at' => now(),
            ]);

            return ['order' => $order, 'totalAmount' => $totalAmount];
        });

        sleep(2);
        Log::info("SYNC: Invoice generated");

        sleep(3);
        Log::info("SYNC: Confirmation email sent");

        sleep(2);
        Log::info("SYNC: Warehouse notified");

        $totalMs = round((microtime(true) - $totalStart) * 1000, 2);

        return [
            'success'     => true,
            'order_id'    => $result['order']->id,
            'total'       => $result['totalAmount'],
            'response_ms' => $totalMs,  
            'note'        => 'المستخدم انتظر كل شيء بما فيها الفاتورة والإيميل',
        ];
    }
}

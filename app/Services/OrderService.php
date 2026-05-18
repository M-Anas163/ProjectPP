<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{

    public function processOrder(int $userId, array $items): array
    {
        $startTime = microtime(true);

        try {
            $order = Order::create([
                'user_id'         => $userId,
                'status'          => 'processing',
                'processing_mode' => 'unsafe',
                'queued_at'       => now(),
            ]);

            $totalAmount = 0;

            foreach ($items as $item) {
                $product = Product::find($item['product_id']);

                if (!$product) {
                    throw new \Exception("المنتج غير موجود: {$item['product_id']}");
                }

                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception(
                        "المخزون غير كافٍ للمنتج: {$product->name}. " .
                        "متوفر: {$product->stock_quantity}, مطلوب: {$item['quantity']}"
                    );
                }

                $product->stock_quantity -= $item['quantity'];
                $product->save();

                $subtotal = $product->price * $item['quantity'];
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

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::warning("UNSAFE ORDER PROCESSED", [
                'order_id'        => $order->id,
                'user_id'         => $userId,
                'processing_ms'   => $processingTime,
                'warning'         => 'Race Condition possible!'
            ]);

            return [
                'success'         => true,
                'order_id'        => $order->id,
                'total'           => $totalAmount,
                'processing_mode' => 'unsafe',
                'processing_ms'   => $processingTime,
            ];

        } catch (\Exception $e) {
            if (isset($order)) {
                $order->update(['status' => 'failed']);
            }

            Log::error("UNSAFE ORDER FAILED", [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'mode'    => 'unsafe'
            ];
        }
    }
}

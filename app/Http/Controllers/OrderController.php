<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrderJob;
use App\Services\OrderService;
use App\Services\SafeOrderService;
use App\Services\AsyncOrderService;
use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService     $unsafeService,
        private readonly SafeOrderService $safeService,
        private readonly AsyncOrderService $asyncService   // ★ جديد
        ) {}


    public function createUnsafe(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
        ]);

        $result = $this->unsafeService->processOrder(
            Auth::id(),
            $request->items
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }


    public function createSafeAtomic(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
        ]);

        $result = $this->safeService->processOrderAtomic(
            Auth::id(),
            $request->items
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }


    public function createSafeLock(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
        ]);

        $result = $this->safeService->processOrderWithLock(
            Auth::id(),
            $request->items
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }


    public function createViaPool(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
        ]);

        $order = Order::create([
            'user_id'         => Auth::id(),
            'status'          => 'pending',
            'processing_mode' => 'pool_with_lock',
            'queued_at'       => now(),
        ]);

        ProcessOrderJob::dispatch(
            Auth::id(),
            $request->items,
            $order->id,
            microtime(true)
        )->onQueue('orders');

        return response()->json([
            'success'   => true,
            'message'   => 'طلبك في قائمة المعالجة',
            'order_id'  => $order->id,
            'status'    => 'pending',
            'track_url' => "/api/orders/{$order->id}/status",
        ], 202);
    }


    public function createSyncSlow(Request $request): JsonResponse
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        $result = $this->asyncService->placeOrderSync(
            Auth::id(), $request->items
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }


    public function createAsync(Request $request): JsonResponse
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        $result = $this->asyncService->placeOrder(
            Auth::id(), $request->items
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }


    public function invoice(int $orderId): JsonResponse
    {
        $invoice = Invoice::where('order_id', $orderId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$invoice) {
            return response()->json([
                'status'  => 'pending',
                'message' => 'الفاتورة قيد الإنشاء في الخلفية...',
            ], 202);
        }

        return response()->json([
            'status'         => $invoice->status,
            'invoice_number' => $invoice->invoice_number,
            'amount'         => $invoice->amount,
            'generated_at'   => $invoice->generated_at,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $order = Order::with('items.product')->findOrFail($id);

        return response()->json([
            'order_id'         => $order->id,
            'status'           => $order->status,
            'processing_mode'  => $order->processing_mode,
            'total_amount'     => $order->total_amount,
            'queued_at'        => $order->queued_at,
            'processed_at'     => $order->processed_at,
            'wait_duration_ms' => $order->processing_duration,
            'items'            => $order->items,
        ]);
    }

   
    public function comparison(): JsonResponse
    {
        $stats = Order::selectRaw("
            processing_mode,
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) as failed,
            AVG(
                TIMESTAMPDIFF(MICROSECOND, queued_at, processed_at) / 1000
            ) as avg_processing_ms
        ")
        ->whereNotNull('processed_at')
        ->groupBy('processing_mode')
        ->get();

        return response()->json([
            'comparison'  => $stats,
            'explanation' => [
                'unsafe'         => '★ بدون حماية — Race Condition مثبتة',
                'safe_atomic'    => '★ المتطلب 1/A — Atomic SQL بدون قفل',
                'safe_lock'      => '★ المتطلب 1/B — Pessimistic Lock (من المحاضرة)',
                'pool_with_lock' => '★ المتطلب 2 — Thread Pool + Lock تكامل',
                'sync_no_nfr3'    => 'بدون NFR3 — المستخدم ينتظر كل شيء',
                'async_nfr3'      => '★ NFR3 — Async Queue للمهام الثانوية',
            ]
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SafeOrderService
{
    // ══════════════════════════════════════════════
    // الطريقة A: Atomic SQL
    // ══════════════════════════════════════════════
    /**
     * ★ الطريقة A: الاستعلام الذري
     *
     * لماذا تحل Race Condition؟
     * لأن UPDATE ... WHERE stock >= N هي عملية واحدة
     * لا يمكن لأي thread آخر الدخول بينها.
     * قاعدة البيانات نفسها تضمن الـ Atomicity.
     *
     * متى تستخدمها؟
     * عندما تريد أسرع حل ممكن بأقل overhead.
     * مناسبة جداً لبيئات الضغط العالي.
     */
    public function processOrderAtomic(int $userId, array $items): array
    {
        $startTime = microtime(true);

        return DB::transaction(function () use ($userId, $items, $startTime) {

            $order = Order::create([
                'user_id'         => $userId,
                'status'          => 'processing',
                'processing_mode' => 'safe_atomic',
                'queued_at'       => now(),
            ]);

            $totalAmount = 0;

            foreach ($items as $item) {
                /**
                 * ★ الجوهر: استعلام واحد يقرأ ويتحقق ويحدث في آنٍ واحد
                 *
                 * UPDATE products
                 *    SET stock_quantity = stock_quantity - N
                 *  WHERE id = X
                 *    AND stock_quantity >= N   ← الشرط الحامي
                 *    AND is_active = 1
                 *
                 * إذا كان stock < N: لا يُنفَّذ التحديث → affected = 0
                 * إذا كان مستخدمان في نفس اللحظة:
                 *   المستخدم الأول يُنفذ التحديث ويأخذ القفل الداخلي للـ row
                 *   المستخدم الثاني ينتظر → يجد stock قد انخفض → affected = 0
                 */
                $affected = DB::table('products')
                    ->where('id', $item['product_id'])
                    ->where('stock_quantity', '>=', $item['quantity'])
                    ->where('is_active', true)
                    ->update([
                        'stock_quantity' => DB::raw("stock_quantity - {$item['quantity']}"),
                        'updated_at'     => now(),
                    ]);

                if ($affected === 0) {
                    // نرمي exception → يُلغي الـ transaction كلها تلقائياً
                    $product = Product::find($item['product_id']);
                    $reason  = !$product
                        ? "المنتج غير موجود"
                        : "المخزون غير كافٍ (متوفر: {$product->stock_quantity})";

                    throw new \Exception("فشل الحجز الذري: {$reason}");
                }

                $product     = Product::find($item['product_id']);
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

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $order->update([
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'processed_at' => now(),
            ]);

            Log::info("ATOMIC ORDER COMPLETED", [
                'order_id'      => $order->id,
                'processing_ms' => $processingTime,
            ]);

            return [
                'success'         => true,
                'order_id'        => $order->id,
                'total'           => $totalAmount,
                'processing_mode' => 'safe_atomic',
                'processing_ms'   => $processingTime,
            ];
        });
    }

    // ══════════════════════════════════════════════
    // الطريقة B: Pessimistic Lock — SELECT FOR UPDATE
    // ══════════════════════════════════════════════
    /**
     * ★ الطريقة B: القفل التشاؤمي — هذا هو المذكور في المحاضرة الأولى
     *
     * كيف يعمل؟
     * SELECT FOR UPDATE تضع قفلاً على الـ row بمجرد قراءتها.
     * أي transaction أخرى تحاول قراءة نفس الـ row ستنتظر
     * حتى تنتهي الـ transaction الأولى وترفع القفل.
     *
     * التسلسل الزمني مع مستخدمَين:
     *
     * مستخدم A: BEGIN → SELECT stock=5 FOR UPDATE (يأخذ القفل)
     * مستخدم B: BEGIN → SELECT ... FOR UPDATE (يُحجب وينتظر)
     * مستخدم A: stock=5 >= 3 ✓ → UPDATE stock=2 → COMMIT (يرفع القفل)
     * مستخدم B: يُكمل القراءة → stock=2 → 2 < 3 → يرمي exception ✓
     *
     * النتيجة: لا تضارب، لا stock سالب، كل قرار صحيح.
     *
     * الفرق عن المتطلب السابع:
     * هنا نُثبت أن القفل يحل Race Condition (المفهوم).
     * المتطلب السابع سيقارن Pessimistic vs Optimistic ويقيس الأداء.
     */
    public function processOrderWithLock(int $userId, array $items): array
    {
        $startTime = microtime(true);

        return DB::transaction(function () use ($userId, $items, $startTime) {

            $order = Order::create([
                'user_id'         => $userId,
                'status'          => 'processing',
                'processing_mode' => 'safe_lock',
                'queued_at'       => now(),
            ]);

            $totalAmount = 0;

            foreach ($items as $item) {
                /**
                 * ★ SELECT FOR UPDATE — القفل التشاؤمي
                 *
                 * lockForUpdate() في Laravel = SELECT ... FOR UPDATE في SQL
                 *
                 * ما يحدث فعلياً في قاعدة البيانات:
                 * 1. يقرأ الـ row ويضع عليها Exclusive Lock
                 * 2. لا أحد يستطيع قراءة أو تعديل هذا الـ row حتى COMMIT
                 * 3. بعد COMMIT يُرفع القفل تلقائياً
                 *
                 * هذا يجب أن يكون داخل DB::transaction حتماً
                 * وإلا فالقفل يُرفع فوراً وتضيع الفائدة
                 */
                $product = Product::where('id', $item['product_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()  // ← SELECT FOR UPDATE
                    ->first();

                // التحقق من وجود المنتج
                if (!$product) {
                    throw new \Exception("المنتج غير موجود: {$item['product_id']}");
                }

                // التحقق من الكمية — الآن القفل يضمن أن هذه القيمة لن تتغير
                // حتى ننتهي من الـ transaction كاملة
                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception(
                        "المخزون غير كافٍ للمنتج: {$product->name}. " .
                        "متوفر: {$product->stock_quantity}, " .
                        "مطلوب: {$item['quantity']}"
                    );
                }

                /**
                 * ★ التحديث آمن 100% هنا لأن:
                 * 1. القفل يمنع أي تعديل خارجي على هذا الـ row
                 * 2. الـ transaction تضمن Atomicity للعمليات المركبة
                 */
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

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $order->update([
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'processed_at' => now(),
            ]);

            Log::info("LOCKED ORDER COMPLETED", [
                'order_id'      => $order->id,
                'processing_ms' => $processingTime,
            ]);

            return [
                'success'         => true,
                'order_id'        => $order->id,
                'total'           => $totalAmount,
                'processing_mode' => 'safe_lock',
                'processing_ms'   => $processingTime,
            ];
        });
    }

    // ══════════════════════════════════════════════
    // للمتطلب الثاني: تُستدعى من داخل الـ Job
    // ══════════════════════════════════════════════
    /**
     * ★ هذه الدالة تُستدعى من ProcessOrderJob (المتطلب الثاني)
     *
     * الـ Job يأتي من Thread Pool → يستدعي هذه الدالة
     * → الأقفال من المتطلب الأول تحمي المخزون داخل الـ Worker
     *
     * هذا هو نقطة التكامل بين المتطلبين:
     * المتطلب الثاني يتحكم في "كم Worker يعمل"
     * المتطلب الأول يتحكم في "كيف يعمل كل Worker بأمان"
     */
    public function processOrderForWorker(int $userId, array $items, int $orderId): array
    {
        $startTime = microtime(true);

        return DB::transaction(function () use ($userId, $items, $orderId, $startTime) {

            $order = Order::findOrFail($orderId);
            $order->update([
                'status'          => 'processing',
                'processing_mode' => 'pool_with_lock',
            ]);

            $totalAmount = 0;

            foreach ($items as $item) {
                // ★ نفس القفل التشاؤمي داخل الـ Worker
                // حتى لو كان هناك N workers يعملون معاً
                // كل واحد يأخذ قفله على الـ row التي يعالجها
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
                    'order_id'   => $orderId,
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $subtotal,
                ]);
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $order->update([
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'processed_at' => now(),
            ]);

            Log::info("WORKER ORDER COMPLETED", [
                'order_id'      => $orderId,
                'worker_pid'    => getmypid(),
                'processing_ms' => $processingTime,
            ]);

            return [
                'success'         => true,
                'order_id'        => $orderId,
                'total'           => $totalAmount,
                'processing_mode' => 'pool_with_lock',
                'processing_ms'   => $processingTime,
            ];
        });
    }
}

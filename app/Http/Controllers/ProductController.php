<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::active()
            ->inStock()
            ->select('id', 'name', 'description', 'price', 'stock_quantity')
            ->get();

        return response()->json([
            'success'  => true,
            'products' => $products,
            'count'    => $products->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::active()->findOrFail($id);

        return response()->json([
            'success' => true,
            'product' => $product,
        ]);
    }
}

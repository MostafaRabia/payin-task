<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show($product)
    {
        if (Cache::has($product)) {
            $product = Cache::get($product);
        } else {
            $product = Product::findOrFail($product);
            Cache::put($product->id, $product, now()->addMinutes(10));
        }

        return response()->json([
            'data' => $product,
        ]);
    }
}

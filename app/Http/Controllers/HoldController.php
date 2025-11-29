<?php

namespace App\Http\Controllers;

use App\Http\Requests\HoldRequest;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class HoldController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(HoldRequest $request)
    {
        $hold = Hold::create([
            'product_id' => $request->input('product_id'),
            'qty' => $request->input('qty'),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        Product::where('id', $request->input('product_id'))->decrement('total_stock', $request->input('qty'));
        
        // Invalidate cache after stock change
        Cache::forget($request->input('product_id'));

        return response()->json([
            'data' => [
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at,
            ]
        ], 201);
    }
}

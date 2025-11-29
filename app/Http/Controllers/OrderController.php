<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Jobs\CheckOrderStatus;
use App\Models\Hold;
use App\Models\Order;

class OrderController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(OrderRequest $request)
    {
        $hold = Hold::find($request->input('hold_id'));

        $order = Order::create([
            'hold_id' => $request->input('hold_id'),
            'total_amount' => round($hold->product->price * $hold->qty, 2),
        ]);

        $hold->update([
            'status' => 'completed',
        ]);

        CheckOrderStatus::dispatch($order);

        return response()->json([
            'data' => $order,
        ], 201);
    }
}

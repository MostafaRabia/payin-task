<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\PendingWebhook;
use App\Models\Product;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HandleWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        if ($log = WebhookLog::where('idempotency_key', $request->input('idempotency_key'))->first()) {
            return response()->json([
                'data' => $log->response_body,
            ], $log->response_status_code);
        }

        $status = $request->input('data.status');
        $hold = Hold::where('id', $request->input('data.hold_id'))->lockForUpdate()->first();

        if (! $hold) {
            $response = [
                'data' => [],
                'msg' => 'Hold not found',
            ];
            $responseStatus = 404;
        } else {
            if ($hold->order) {
                $hold->order->update([
                    'status' => $status,
                ]);
            } else {
                PendingWebhook::create([
                    'hold_id' => $hold->id,
                    'status' => $status,
                ]);
            }

            if ($status === 'failed') {
                Product::where('id', $hold->product_id)->increment('total_stock', $hold->qty);
                // Invalidate cache after stock restoration
                Cache::forget($hold->product_id);
            }

            $response = [
                'data' => [
                    'hold_id' => $hold->id,
                    'status' => $status,
                ],
            ];
            $responseStatus = 200;

        }

        WebhookLog::create([
            'idempotency_key' => $request->input('idempotency_key'),
            'response_body' => $response,
            'response_status_code' => $responseStatus,
        ]);

        return response()->json($response, $responseStatus);
    }
}

<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\PendingWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class CheckOrderStatus implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    )
    {
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $webhook = PendingWebhook::where('hold_id', $this->order->hold_id)->first();

        if ($webhook) {
            $this->order->update(['status' => $webhook->status]);
            $webhook->delete();

            if ($webhook->status === 'failed') {
                $this->order->hold->product->increment('total_stock', $this->order->hold->qty);
                // Invalidate cache after stock restoration
                Cache::forget($this->order->hold->product_id);
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Hold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ExpireHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-holds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make holds expire after its expires_at';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $holds = Hold::with('product')
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($holds as $hold) {
            $hold->update(['status' => 'expired']);
            $hold->product->increment('total_stock', $hold->qty);
            // Invalidate cache after stock restoration
            Cache::forget($hold->product_id);
        }
    }
}

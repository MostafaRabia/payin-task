<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireHolds;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExpireHoldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_holds_are_marked_as_expired(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create an expired hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->subMinutes(5), // Expired 5 minutes ago
        ]);

        $product->decrement('total_stock', 10);

        Artisan::call('app:expire-holds');

        $this->assertEquals('expired', $hold->fresh()->status);
    }

    public function test_expired_holds_restore_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create an expired hold
        Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->subMinutes(5),
        ]);

        $product->decrement('total_stock', 10);

        $this->assertEquals(90, $product->fresh()->total_stock);

        Artisan::call('app:expire-holds');

        // Stock should be restored
        $this->assertEquals(100, $product->fresh()->total_stock);
    }

    public function test_non_expired_holds_are_not_affected(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create a non-expired hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5), // Expires in 5 minutes
        ]);

        $product->decrement('total_stock', 10);

        Artisan::call('app:expire-holds');

        $this->assertEquals('pending', $hold->fresh()->status);
        $this->assertEquals(90, $product->fresh()->total_stock);
    }

    public function test_completed_holds_are_not_expired(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create a completed hold that has passed its expires_at
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'completed',
            'expires_at' => now()->subMinutes(5),
        ]);

        $product->decrement('total_stock', 10);

        Artisan::call('app:expire-holds');

        // Should still be completed, not expired
        $this->assertEquals('completed', $hold->fresh()->status);
        // Stock should not be restored
        $this->assertEquals(90, $product->fresh()->total_stock);
    }

    public function test_already_expired_holds_are_not_processed_again(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create an already expired hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'expired',
            'expires_at' => now()->subMinutes(10),
        ]);

        // Manually set stock to 90 (as if it was already restored)
        $product->update(['total_stock' => 90]);

        Artisan::call('app:expire-holds');

        // Stock should not change
        $this->assertEquals(90, $product->fresh()->total_stock);
    }

    public function test_multiple_expired_holds_are_processed(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create multiple expired holds
        $hold1 = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->subMinutes(5),
        ]);

        $hold2 = Hold::create([
            'product_id' => $product->id,
            'qty' => 20,
            'status' => 'pending',
            'expires_at' => now()->subMinutes(3),
        ]);

        $product->decrement('total_stock', 30);

        $this->assertEquals(70, $product->fresh()->total_stock);

        Artisan::call('app:expire-holds');

        $this->assertEquals('expired', $hold1->fresh()->status);
        $this->assertEquals('expired', $hold2->fresh()->status);
        $this->assertEquals(100, $product->fresh()->total_stock);
    }
}

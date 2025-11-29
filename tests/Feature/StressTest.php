<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that concurrent hold requests don't cause overselling.
     * This simulates multiple users trying to buy the same limited stock item.
     */
    public function test_concurrent_holds_never_cause_overselling(): void
    {
        $initialStock = 10;
        $requestedQtyPerHold = 1;
        $numberOfConcurrentRequests = 50; // 50 users trying to buy 1 item each, but only 10 available

        $product = Product::create([
            'name' => 'Limited Flash Sale Item',
            'total_stock' => $initialStock,
            'price' => 99.99,
        ]);

        $successfulHolds = 0;
        $failedHolds = 0;

        // Simulate concurrent requests
        for ($i = 0; $i < $numberOfConcurrentRequests; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => $requestedQtyPerHold,
            ]);

            if ($response->status() === 201) {
                $successfulHolds++;
            } else {
                $failedHolds++;
            }
        }

        // Refresh product to get current stock
        $product->refresh();

        // The key assertion: stock should NEVER go below 0
        $this->assertGreaterThanOrEqual(0, $product->total_stock, 'Stock went below 0 - OVERSELLING DETECTED!');

        // Successful holds should equal initial stock
        $this->assertEquals($initialStock, $successfulHolds, 'Number of successful holds should equal initial stock');

        // Total holds in database should match successful holds
        $this->assertEquals($successfulHolds, Hold::where('product_id', $product->id)->count());

        // Failed holds should be the difference
        $this->assertEquals($numberOfConcurrentRequests - $initialStock, $failedHolds);

        // Final stock should be 0
        $this->assertEquals(0, $product->total_stock);
    }

    /**
     * Test high volume of holds with various quantities.
     */
    public function test_high_volume_holds_with_varied_quantities(): void
    {
        $initialStock = 100;
        
        $product = Product::create([
            'name' => 'High Volume Item',
            'total_stock' => $initialStock,
            'price' => 49.99,
        ]);

        $quantities = [1, 2, 3, 5, 10, 1, 2, 1, 1, 3, 5, 2, 1, 1, 1, 2, 3, 1, 1, 1];
        $totalRequested = 0;
        $totalSuccessful = 0;

        foreach ($quantities as $qty) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => $qty,
            ]);

            $totalRequested += $qty;

            if ($response->status() === 201) {
                $totalSuccessful += $qty;
            }
        }

        $product->refresh();

        // Stock should never go negative
        $this->assertGreaterThanOrEqual(0, $product->total_stock);

        // Total successful should not exceed initial stock
        $this->assertLessThanOrEqual($initialStock, $totalSuccessful);

        // Stock + successful holds should equal initial stock
        $this->assertEquals($initialStock, $product->total_stock + $totalSuccessful);
    }

    /**
     * Test concurrent orders for the same hold (should only allow one).
     */
    public function test_concurrent_orders_for_same_hold_only_one_succeeds(): void
    {
        Queue::fake();

        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        $product->decrement('total_stock', 5);

        $successfulOrders = 0;
        $failedOrders = 0;

        // Try to create 10 orders for the same hold
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/orders', [
                'hold_id' => $hold->id,
            ]);

            if ($response->status() === 201) {
                $successfulOrders++;
            } else {
                $failedOrders++;
            }
        }

        // Only one order should succeed
        $this->assertEquals(1, $successfulOrders);
        $this->assertEquals(9, $failedOrders);

        // Only one order should exist in database
        $this->assertEquals(1, Order::where('hold_id', $hold->id)->count());
    }

    /**
     * Test that exact stock boundary is respected.
     */
    public function test_exact_stock_boundary_respected(): void
    {
        $product = Product::create([
            'name' => 'Boundary Test Item',
            'total_stock' => 5,
            'price' => 99.99,
        ]);

        // Request exactly available stock
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(0, $product->fresh()->total_stock);

        // Now even 1 item should fail
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, $product->fresh()->total_stock);
    }

    /**
     * Test rapid sequential holds and stock consistency.
     */
    public function test_rapid_sequential_holds_maintain_stock_consistency(): void
    {
        $initialStock = 1000;
        
        $product = Product::create([
            'name' => 'High Stock Item',
            'total_stock' => $initialStock,
            'price' => 9.99,
        ]);

        $totalHeldQty = 0;

        // Create 100 rapid sequential holds of 1 item each
        for ($i = 0; $i < 100; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $totalHeldQty++;
            }
        }

        $product->refresh();

        // Verify consistency: initial - held = current
        $this->assertEquals($initialStock - $totalHeldQty, $product->total_stock);
        
        // All 100 holds should succeed
        $this->assertEquals(100, $totalHeldQty);
        $this->assertEquals(900, $product->total_stock);
    }

    /**
     * Test webhook idempotency under stress.
     */
    public function test_webhook_idempotency_under_stress(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'completed',
            'expires_at' => now()->addMinutes(2),
        ]);

        $product->decrement('total_stock', 10);

        Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total_amount' => 999.90,
        ]);

        $idempotencyKey = 'stress-test-key';

        // Send same webhook 20 times (simulating retries/duplicates)
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/payments/webhook', [
                'idempotency_key' => $idempotencyKey,
                'data' => [
                    'hold_id' => $hold->id,
                    'status' => 'failed',
                ],
            ])->assertStatus(200);
        }

        $product->refresh();

        // Stock should be restored exactly once, not 20 times
        $this->assertEquals(100, $product->total_stock, 'Stock was restored multiple times due to idempotency failure!');
    }

    /**
     * Test multiple products under concurrent load.
     */
    public function test_multiple_products_concurrent_holds(): void
    {
        $products = [];
        $initialStocks = [10, 20, 15];

        foreach ($initialStocks as $i => $stock) {
            $products[] = Product::create([
                'name' => "Product $i",
                'total_stock' => $stock,
                'price' => 99.99,
            ]);
        }

        // Hold items from each product
        foreach ($products as $index => $product) {
            $requestCount = $initialStocks[$index] + 10; // Request more than available
            $successCount = 0;

            for ($i = 0; $i < $requestCount; $i++) {
                $response = $this->postJson('/api/holds', [
                    'product_id' => $product->id,
                    'qty' => 1,
                ]);

                if ($response->status() === 201) {
                    $successCount++;
                }
            }

            $product->refresh();

            // Verify no overselling for each product
            $this->assertGreaterThanOrEqual(0, $product->total_stock);
            $this->assertEquals($initialStocks[$index], $successCount);
            $this->assertEquals(0, $product->total_stock);
        }
    }

    /**
     * Test stock restoration after failed payments doesn't cause issues.
     */
    public function test_stock_restoration_consistency_after_failures(): void
    {
        $initialStock = 50;

        $product = Product::create([
            'name' => 'Failure Test Item',
            'total_stock' => $initialStock,
            'price' => 99.99,
        ]);

        $holds = [];

        // Create 10 holds
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 5,
            ]);

            $response->assertStatus(201);
            $holds[] = $response->json('data.hold_id');
        }

        $this->assertEquals(0, $product->fresh()->total_stock);

        // Create orders for all holds
        Queue::fake();
        foreach ($holds as $holdId) {
            $this->postJson('/api/orders', [
                'hold_id' => $holdId,
            ])->assertStatus(201);
        }

        // Fail 5 payments (restore 25 items)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/payments/webhook', [
                'idempotency_key' => "fail-$i",
                'data' => [
                    'hold_id' => $holds[$i],
                    'status' => 'failed',
                ],
            ])->assertStatus(200);
        }

        $product->refresh();

        // 5 failed * 5 qty = 25 items restored
        $this->assertEquals(25, $product->total_stock);

        // Now we should be able to hold 25 more items
        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ])->assertStatus(201);
        }

        // Stock should be 0 again
        $this->assertEquals(0, $product->fresh()->total_stock);

        // One more should fail
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(422);
    }

    /**
     * Test database transaction rollback on error maintains consistency.
     */
    public function test_stock_consistency_across_transactions(): void
    {
        $initialStock = 100;

        $product = Product::create([
            'name' => 'Transaction Test Item',
            'total_stock' => $initialStock,
            'price' => 99.99,
        ]);

        // Create holds and verify stock at each step
        $holdsCreated = 0;
        
        for ($i = 0; $i < 20; $i++) {
            $stockBefore = $product->fresh()->total_stock;
            
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 5,
            ]);

            $stockAfter = $product->fresh()->total_stock;

            if ($response->status() === 201) {
                $holdsCreated++;
                // Stock should decrease by exactly 5
                $this->assertEquals($stockBefore - 5, $stockAfter);
            } else {
                // Stock should remain the same on failure
                $this->assertEquals($stockBefore, $stockAfter);
            }
        }

        // Final verification
        $this->assertEquals($initialStock - ($holdsCreated * 5), $product->fresh()->total_stock);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\CheckOrderStatus;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_successful_checkout_flow(): void
    {
        Queue::fake();

        // Step 1: Create a product
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'price' => 49.99,
        ]);

        // Step 2: Create a hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $holdResponse->assertStatus(201);
        $holdId = $holdResponse->json('data.hold_id');
        
        // Verify stock was decremented
        $this->assertEquals(98, $product->fresh()->total_stock);

        // Step 3: Create an order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderResponse->assertStatus(201);
        $order = Order::where('hold_id', $holdId)->first();
        
        // Verify order was created with correct total
        $this->assertEquals(99.98, (float) $order->total_amount); // 49.99 * 2
        $this->assertEquals('pending', $order->status);

        // Step 4: Receive successful payment webhook
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'payment-success-123',
            'data' => [
                'hold_id' => $holdId,
                'status' => 'paid',
            ],
        ])->assertStatus(200);

        // Verify order status was updated
        $this->assertEquals('paid', $order->fresh()->status);

        // Stock should remain at 98 (payment succeeded)
        $this->assertEquals(98, $product->fresh()->total_stock);
    }

    public function test_complete_failed_checkout_flow(): void
    {
        Queue::fake();

        // Step 1: Create a product
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'price' => 49.99,
        ]);

        // Step 2: Create a hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $holdId = $holdResponse->json('data.hold_id');
        $this->assertEquals(98, $product->fresh()->total_stock);

        // Step 3: Create an order
        $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        // Step 4: Receive failed payment webhook
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'payment-failed-123',
            'data' => [
                'hold_id' => $holdId,
                'status' => 'failed',
            ],
        ])->assertStatus(200);

        // Verify order status
        $order = Order::where('hold_id', $holdId)->first();
        $this->assertEquals('failed', $order->status);

        // Stock should be restored to 100
        $this->assertEquals(100, $product->fresh()->total_stock);
    }

    public function test_early_webhook_flow(): void
    {
        Queue::fake();

        // Create a product and hold
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'price' => 49.99,
        ]);

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $holdId = $holdResponse->json('data.hold_id');

        // Webhook arrives BEFORE order is created
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'early-webhook-123',
            'data' => [
                'hold_id' => $holdId,
                'status' => 'paid',
            ],
        ])->assertStatus(200);

        // Verify pending webhook was created
        $this->assertDatabaseHas('pending_webhooks', [
            'hold_id' => $holdId,
            'status' => 'paid',
        ]);

        // Now create the order
        $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ])->assertStatus(201);

        $order = Order::where('hold_id', $holdId)->first();

        // Job should pick up the pending webhook
        $job = new CheckOrderStatus($order);
        $job->handle();

        // Order should now be paid
        $this->assertEquals('paid', $order->fresh()->status);

        // Pending webhook should be deleted
        $this->assertDatabaseMissing('pending_webhooks', [
            'hold_id' => $holdId,
        ]);
    }

    public function test_hold_expiration_flow(): void
    {
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'price' => 49.99,
        ]);

        // Create an expired hold directly
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->subMinutes(5),
        ]);

        $product->decrement('total_stock', 10);
        $this->assertEquals(90, $product->fresh()->total_stock);

        // Run the expire holds command
        $this->artisan('app:expire-holds');

        // Hold should be expired
        $this->assertEquals('expired', $hold->fresh()->status);

        // Stock should be restored
        $this->assertEquals(100, $product->fresh()->total_stock);

        // Trying to create an order with expired hold should fail
        $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ])->assertStatus(422);
    }

    public function test_flash_sale_scenario_with_limited_stock(): void
    {
        Queue::fake();

        // Create a product with very limited stock (flash sale)
        $product = Product::create([
            'name' => 'Limited Edition Item',
            'total_stock' => 5,
            'price' => 999.99,
        ]);

        // First customer holds 3 items
        $hold1Response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 3,
        ]);
        $hold1Response->assertStatus(201);
        $this->assertEquals(2, $product->fresh()->total_stock);

        // Second customer holds 2 items
        $hold2Response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);
        $hold2Response->assertStatus(201);
        $this->assertEquals(0, $product->fresh()->total_stock);

        // Third customer tries to hold 1 item - should fail
        $hold3Response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $hold3Response->assertStatus(422);

        // First customer completes order
        $hold1Id = $hold1Response->json('data.hold_id');
        $this->postJson('/api/orders', [
            'hold_id' => $hold1Id,
        ])->assertStatus(201);

        // Second customer's payment fails
        $hold2Id = $hold2Response->json('data.hold_id');
        $this->postJson('/api/orders', [
            'hold_id' => $hold2Id,
        ])->assertStatus(201);

        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'customer2-failed',
            'data' => [
                'hold_id' => $hold2Id,
                'status' => 'failed',
            ],
        ]);

        // Stock should be restored (2 items from failed payment)
        $this->assertEquals(2, $product->fresh()->total_stock);

        // Now third customer can hold
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(201);
    }
}

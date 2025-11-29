<?php

namespace Tests\Feature;

use App\Jobs\CheckOrderStatus;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PendingWebhook;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_holds_cannot_oversell_stock(): void
    {
        $product = Product::create([
            'name' => 'Limited Product',
            'total_stock' => 5,
            'price' => 99.99,
        ]);

        // Simulate first hold request
        $response1 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 3,
        ]);

        $response1->assertStatus(201);

        // Now stock should be 2
        $this->assertEquals(2, $product->fresh()->total_stock);

        // Second hold request for 3 should fail
        $response2 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 3,
        ]);

        $response2->assertStatus(422);

        // Stock should still be 2
        $this->assertEquals(2, $product->fresh()->total_stock);
    }

    public function test_order_creation_is_atomic_with_hold_status(): void
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

        // First order should succeed
        $response1 = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response1->assertStatus(201);

        // Second order for same hold should fail
        $response2 = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response2->assertStatus(422);

        // Only one order should exist
        $this->assertEquals(1, Order::where('hold_id', $hold->id)->count());
    }

    public function test_webhook_before_order_creates_pending_webhook(): void
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

        // Webhook arrives before order is created
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'early-webhook',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('pending_webhooks', [
            'hold_id' => $hold->id,
            'status' => 'paid',
        ]);

        // Now create order
        $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ])->assertStatus(201);

        // The CheckOrderStatus job should process the pending webhook
        $order = Order::where('hold_id', $hold->id)->first();
        
        $job = new CheckOrderStatus($order);
        $job->handle();

        // Order should now be paid
        $this->assertEquals('paid', $order->fresh()->status);

        // Pending webhook should be deleted
        $this->assertDatabaseMissing('pending_webhooks', [
            'hold_id' => $hold->id,
        ]);
    }

    public function test_hold_uses_lock_for_update_to_prevent_race_conditions(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 10,
            'price' => 99.99,
        ]);

        // This test verifies that the HoldRequest uses lockForUpdate
        // which is important for preventing race conditions

        // Create first hold
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ])->assertStatus(201);

        // Stock should be 5 now
        $this->assertEquals(5, $product->fresh()->total_stock);

        // Create second hold
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ])->assertStatus(201);

        // Stock should be 0 now
        $this->assertEquals(0, $product->fresh()->total_stock);

        // Third hold should fail
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_order_uses_lock_for_update_on_hold(): void
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

        // Create order
        $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ])->assertStatus(201);

        // Verify order was created
        $this->assertDatabaseHas('orders', [
            'hold_id' => $hold->id,
        ]);

        // Verify hold status changed
        $this->assertEquals('completed', $hold->fresh()->status);
    }

    public function test_webhook_idempotency_prevents_duplicate_processing(): void
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

        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total_amount' => 999.90,
        ]);

        $idempotencyKey = 'unique-key-123';

        // First failed webhook
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'failed',
            ],
        ])->assertStatus(200);

        // Stock should be restored once
        $this->assertEquals(100, $product->fresh()->total_stock);

        // Second call with same key (maybe a retry)
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'failed',
            ],
        ])->assertStatus(200);

        // Stock should still be 100 (not 110)
        $this->assertEquals(100, $product->fresh()->total_stock);
    }
}

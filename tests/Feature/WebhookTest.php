<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\PendingWebhook;
use App\Models\Product;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderWithHold(): array
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'status' => 'completed',
            'expires_at' => now()->addMinutes(2),
        ]);

        $product->decrement('total_stock', 5);

        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total_amount' => 499.95,
        ]);

        return [
            'product' => $product->fresh(),
            'hold' => $hold,
            'order' => $order,
        ];
    }

    public function test_webhook_updates_order_status_to_paid(): void
    {
        ['hold' => $hold, 'order' => $order] = $this->createOrderWithHold();

        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-123',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.hold_id', $hold->id)
            ->assertJsonPath('data.status', 'paid');

        $this->assertEquals('paid', $order->fresh()->status);
    }

    public function test_webhook_updates_order_status_to_failed_and_restores_stock(): void
    {
        ['product' => $product, 'hold' => $hold, 'order' => $order] = $this->createOrderWithHold();

        $initialStock = $product->total_stock;

        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-456',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'failed',
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('failed', $order->fresh()->status);

        // Stock should be restored
        $this->assertEquals($initialStock + 5, $product->fresh()->total_stock);
    }

    public function test_webhook_creates_pending_webhook_if_order_not_exists(): void
    {
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

        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-789',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('pending_webhooks', [
            'hold_id' => $hold->id,
            'status' => 'paid',
        ]);
    }

    public function test_webhook_returns_404_for_non_existent_hold(): void
    {
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-notfound',
            'data' => [
                'hold_id' => 999,
                'status' => 'paid',
            ],
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('msg', 'Hold not found');
    }

    public function test_webhook_idempotency_returns_same_response(): void
    {
        ['hold' => $hold] = $this->createOrderWithHold();

        $idempotencyKey = 'idempotent-key-123';

        // First request
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ]);

        $response1->assertStatus(200);

        // Second request with same idempotency key - should return cached response
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'failed', // Different status, but should be ignored
            ],
        ]);

        $response2->assertStatus(200);

        // Should only have one webhook log
        $this->assertEquals(1, WebhookLog::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_webhook_log_is_created(): void
    {
        ['hold' => $hold] = $this->createOrderWithHold();

        $idempotencyKey = 'log-test-key';

        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ]);

        $this->assertDatabaseHas('webhook_logs', [
            'idempotency_key' => $idempotencyKey,
            'response_status_code' => 200,
        ]);
    }

    public function test_failed_webhook_restores_stock_even_for_pending_hold(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        $product->decrement('total_stock', 10);

        $this->assertEquals(90, $product->fresh()->total_stock);

        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'failed-webhook-test',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'failed',
            ],
        ]);

        // Stock should be restored
        $this->assertEquals(100, $product->fresh()->total_stock);
    }

    public function test_different_idempotency_keys_create_separate_logs(): void
    {
        ['hold' => $hold] = $this->createOrderWithHold();

        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-1',
            'data' => [
                'hold_id' => $hold->id,
                'status' => 'paid',
            ],
        ]);

        // Create another order for second webhook
        $product2 = Product::create([
            'name' => 'Test Product 2',
            'total_stock' => 100,
            'price' => 50.00,
        ]);

        $hold2 = Hold::create([
            'product_id' => $product2->id,
            'qty' => 2,
            'status' => 'completed',
            'expires_at' => now()->addMinutes(2),
        ]);

        Order::create([
            'hold_id' => $hold2->id,
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-2',
            'data' => [
                'hold_id' => $hold2->id,
                'status' => 'paid',
            ],
        ]);

        $this->assertEquals(2, WebhookLog::count());
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\CheckOrderStatus;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PendingWebhook;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithHold(int $stock = 100, int $qty = 5): array
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => $stock,
            'price' => 99.99,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => $qty,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        $product->decrement('total_stock', $qty);

        return ['product' => $product->fresh(), 'hold' => $hold];
    }

    public function test_can_create_order_with_valid_hold(): void
    {
        Queue::fake();

        ['product' => $product, 'hold' => $hold] = $this->createProductWithHold();

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'hold_id',
                    'total_amount',
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'hold_id' => $hold->id,
            'status' => 'pending',
        ]);

        // Hold should be marked as completed
        $this->assertEquals('completed', $hold->fresh()->status);

        // Check that job was dispatched
        Queue::assertPushed(CheckOrderStatus::class);
    }

    public function test_order_total_amount_calculated_correctly(): void
    {
        Queue::fake();

        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 25.50,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 3,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        // 25.50 * 3 = 76.50
        $order = Order::where('hold_id', $hold->id)->first();
        $this->assertEquals(76.50, (float) $order->total_amount);
    }

    public function test_cannot_create_order_with_expired_hold(): void
    {
        ['hold' => $hold] = $this->createProductWithHold();

        $hold->update(['status' => 'expired']);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_id']);
    }

    public function test_cannot_create_order_with_completed_hold(): void
    {
        ['hold' => $hold] = $this->createProductWithHold();

        $hold->update(['status' => 'completed']);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_id']);
    }

    public function test_cannot_create_order_with_non_existent_hold(): void
    {
        $response = $this->postJson('/api/orders', [
            'hold_id' => 999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_id']);
    }

    public function test_cannot_create_duplicate_order_for_same_hold(): void
    {
        Queue::fake();

        ['hold' => $hold] = $this->createProductWithHold();

        // First order should succeed
        $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ])->assertStatus(201);

        // Second order for same hold should fail
        $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ])->assertStatus(422);
    }

    public function test_order_requires_hold_id(): void
    {
        $response = $this->postJson('/api/orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hold_id']);
    }

    public function test_check_order_status_job_processes_pending_webhook(): void
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

        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total_amount' => 499.95,
        ]);

        PendingWebhook::create([
            'hold_id' => $hold->id,
            'status' => 'paid',
        ]);

        $job = new CheckOrderStatus($order);
        $job->handle();

        $this->assertEquals('paid', $order->fresh()->status);
        $this->assertDatabaseMissing('pending_webhooks', ['hold_id' => $hold->id]);
    }

    public function test_check_order_status_job_does_nothing_without_pending_webhook(): void
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

        $order = Order::create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'total_amount' => 499.95,
        ]);

        $job = new CheckOrderStatus($order);
        $job->handle();

        // Status should remain pending
        $this->assertEquals('pending', $order->fresh()->status);
    }
}

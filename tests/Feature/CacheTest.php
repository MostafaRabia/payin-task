<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_cached_on_first_read(): void
    {
        $product = Product::create([
            'name' => 'Cached Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Ensure cache is empty
        Cache::forget($product->id);
        $this->assertFalse(Cache::has($product->id));

        // First request should cache the product
        $this->getJson("/api/products/{$product->id}")
            ->assertStatus(200);

        // Now cache should have the product
        $this->assertTrue(Cache::has($product->id));
    }

    public function test_cached_product_is_returned_without_db_query(): void
    {
        $product = Product::create([
            'name' => 'Cached Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // First request to cache
        $this->getJson("/api/products/{$product->id}");

        // Count queries for second request
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'products')) {
                $queryCount++;
            }
        });

        // Second request should use cache
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);

        // No product queries should be made (using cache)
        $this->assertEquals(0, $queryCount, 'Product was queried from database instead of cache');
    }

    public function test_cache_is_invalidated_after_hold_decrements_stock(): void
    {
        $product = Product::create([
            'name' => 'Hold Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Cache the product via GET request
        $this->getJson("/api/products/{$product->id}")
            ->assertJsonPath('data.total_stock', 100);

        // Create a hold (this decrements stock)
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ])->assertStatus(201);

        // Product was updated, so cache should be invalidated and new stock returned
        $this->getJson("/api/products/{$product->id}")
            ->assertJsonPath('data.total_stock', 90);
    }

    public function test_cache_expires_after_ttl(): void
    {
        $product = Product::create([
            'name' => 'TTL Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Cache with very short TTL for testing
        Cache::put($product->id, $product, now()->addSeconds(1));
        $this->assertTrue(Cache::has($product->id));

        // Wait for expiration
        sleep(2);

        // Cache should be expired
        $this->assertFalse(Cache::has($product->id));
    }

    public function test_cache_returns_correct_product_data_structure(): void
    {
        $product = Product::create([
            'name' => 'Structure Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'total_stock',
                    'price',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_cache_handles_high_read_volume(): void
    {
        $product = Product::create([
            'name' => 'High Volume Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // First request to cache
        $this->getJson("/api/products/{$product->id}");

        // Track DB queries
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'products')) {
                $queryCount++;
            }
        });

        // Make 100 requests
        for ($i = 0; $i < 100; $i++) {
            $this->getJson("/api/products/{$product->id}")
                ->assertStatus(200);
        }

        // No database queries should be made for products
        $this->assertEquals(0, $queryCount, 'Database was queried instead of using cache');
    }

    public function test_cache_works_correctly_after_payment_webhook_updates_stock(): void
    {
        $product = Product::create([
            'name' => 'Webhook Cache Test',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $holdId = $holdResponse->json('data.hold_id');

        // Cache shows 90
        $this->getJson("/api/products/{$product->id}")
            ->assertJsonPath('data.total_stock', 90);

        // Create order
        \Illuminate\Support\Facades\Queue::fake();
        $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        // Failed payment webhook restores stock
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-webhook-cache',
            'data' => [
                'hold_id' => $holdId,
                'status' => 'failed',
            ],
        ]);

        // Cache should reflect restored stock
        $this->getJson("/api/products/{$product->id}")
            ->assertJsonPath('data.total_stock', 100);
    }
}

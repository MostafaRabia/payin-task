<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_product_details(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Test Product')
            ->assertJsonPath('data.total_stock', 100);
        
        // Price could be returned as string or float depending on caching
        $this->assertEquals(99.99, (float) $response->json('data.price'));
    }

    public function test_product_is_cached_after_first_request(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        // First request - should cache the product
        $this->getJson("/api/products/{$product->id}");

        $this->assertTrue(Cache::has($product->id));
    }

    public function test_returns_404_for_non_existent_product(): void
    {
        $response = $this->getJson('/api/products/999');

        $response->assertStatus(404);
    }
}

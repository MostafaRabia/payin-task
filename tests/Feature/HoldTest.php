<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_hold_with_sufficient_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'hold_id',
                    'expires_at',
                ],
            ]);

        $this->assertDatabaseHas('holds', [
            'product_id' => $product->id,
            'qty' => 5,
            'status' => 'pending',
        ]);

        // Check stock was decremented
        $this->assertEquals(95, $product->fresh()->total_stock);
    }

    public function test_cannot_create_hold_with_insufficient_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 3,
            'price' => 99.99,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);

        // Stock should not change
        $this->assertEquals(3, $product->fresh()->total_stock);
    }

    public function test_cannot_create_hold_for_non_existent_product(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => 999,
            'qty' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_hold_requires_product_id_and_qty(): void
    {
        $response = $this->postJson('/api/holds', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'qty']);
    }

    public function test_qty_must_be_positive_integer(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qty']);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qty']);
    }

    public function test_multiple_holds_decrement_stock_correctly(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 30,
        ])->assertStatus(201);

        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 20,
        ])->assertStatus(201);

        $this->assertEquals(50, $product->fresh()->total_stock);

        // Third hold should fail - only 50 left
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 60,
        ])->assertStatus(422);

        $this->assertEquals(50, $product->fresh()->total_stock);
    }

    public function test_hold_expires_at_is_set_correctly(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'total_stock' => 100,
            'price' => 99.99,
        ]);

        $beforeCreate = now();

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $holdId = $response->json('data.hold_id');
        $hold = Hold::find($holdId);

        // expires_at should be approximately 2 minutes from now
        $this->assertTrue($hold->expires_at >= $beforeCreate->addMinutes(2)->subSeconds(5));
        $this->assertTrue($hold->expires_at <= now()->addMinutes(2)->addSeconds(5));
    }
}

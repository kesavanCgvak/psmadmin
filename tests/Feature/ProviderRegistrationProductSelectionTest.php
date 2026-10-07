<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProviderRegistrationProductSelectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_provider_registration_rejects_zero_products(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.product_selections.0', 'At least 10 products are required.');
    }

    public function test_provider_registration_rejects_fewer_than_ten_products(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $this->selections($this->createProducts(9)),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.product_selections.0', 'At least 10 products are required.');
    }

    public function test_provider_registration_accepts_exactly_ten_products(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $this->selections($this->createProducts(10), [
                0 => ['quantity' => 1, 'rental_price' => 0],
            ]),
        ]);

        $response->assertStatus(422);
        $this->assertNoProductSelectionErrors($response->json('errors') ?? []);
    }

    public function test_provider_registration_accepts_more_than_ten_products(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $this->selections($this->createProducts(11), [
                1 => ['quantity' => 2, 'rental_price' => 50],
            ]),
        ]);

        $response->assertStatus(422);
        $this->assertNoProductSelectionErrors($response->json('errors') ?? []);
    }

    public function test_provider_registration_rejects_duplicate_product_ids(): void
    {
        $products = $this->createProducts(10);
        $selections = $this->selections($products);
        $selections[9]['product_id'] = $products[0]->id;

        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $selections,
        ]);

        $response->assertStatus(422);
        $this->assertContains(
            'Each product can only be selected once.',
            collect($response->json('errors'))->flatten()->all()
        );
    }

    public function test_provider_registration_rejects_unknown_product_ids(): void
    {
        $selections = $this->selections($this->createProducts(10));
        $selections[3]['product_id'] = 999999999;

        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $selections,
        ]);

        $response->assertStatus(422);
        $this->assertContains(
            'One or more selected products do not exist.',
            collect($response->json('errors'))->flatten()->all()
        );
    }

    public function test_provider_registration_rejects_invalid_quantity_and_rental_price(): void
    {
        $selections = $this->selections($this->createProducts(10));
        $selections[0]['quantity'] = 0;
        $selections[1]['rental_price'] = -1;

        $response = $this->postJson('/api/register', [
            'account_type' => 'provider',
            'product_selections' => $selections,
        ]);

        $response->assertStatus(422);
        $messages = collect($response->json('errors'))->flatten()->all();
        $this->assertContains('Quantity must be greater than 0.', $messages);
        $this->assertContains('Rental price must be greater than or equal to 0.', $messages);
    }

    public function test_user_registration_does_not_require_products(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'user',
        ]);

        $response->assertStatus(422);
        $this->assertNoProductSelectionErrors($response->json('errors') ?? []);
    }

    public function test_user_registration_rejects_product_selections(): void
    {
        $response = $this->postJson('/api/register', [
            'account_type' => 'user',
            'product_selections' => $this->selections($this->createProducts(10)),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath(
                'errors.product_selections.0',
                'Product selections are only allowed for provider registration.'
            );
    }

    /**
     * @return list<Product>
     */
    private function createProducts(int $count): array
    {
        $products = [];

        for ($i = 0; $i < $count; $i++) {
            $products[] = Product::create([
                'model' => 'Registration Product '.$i.' '.uniqid(),
                'psm_code' => 'PSM-REG-'.uniqid(),
                'is_verified' => 1,
            ]);
        }

        return $products;
    }

    /**
     * @param  list<Product>  $products
     * @param  array<int, array<string, mixed>>  $overrides
     * @return list<array<string, mixed>>
     */
    private function selections(array $products, array $overrides = []): array
    {
        return array_map(function (Product $product, int $index) use ($overrides) {
            return array_merge([
                'product_id' => $product->id,
                'quantity' => 1,
                'rental_price' => 1,
            ], $overrides[$index] ?? []);
        }, $products, array_keys($products));
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function assertNoProductSelectionErrors(array $errors): void
    {
        foreach (array_keys($errors) as $key) {
            $this->assertFalse(
                str_starts_with((string) $key, 'product_selections'),
                'Unexpected product selection error on '.$key.': '.json_encode($errors[$key])
            );
        }
    }
}

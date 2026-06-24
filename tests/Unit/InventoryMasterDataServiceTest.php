<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\InventoryMaster\InventoryMasterDataService;
use Tests\TestCase;

class InventoryMasterDataServiceTest extends TestCase
{
    public function test_build_response_returns_null_for_missing_fields(): void
    {
        $product = new Product();
        $product->id = 42;
        $product->psm_code = 'PSM00042';

        $service = new InventoryMasterDataService();
        $response = $service->buildResponse($product);

        $this->assertSame(42, $response['product_id']);
        $this->assertNull($response['equipment_id']);
        $this->assertNull($response['dimensions']['height']);
        $this->assertNull($response['dimensions']['width']);
        $this->assertNull($response['dimensions']['length']);
        $this->assertNull($response['dimensions']['weight']);
        $this->assertNull($response['country_of_origin']);
        $this->assertNull($response['hsn_code']);
        $this->assertNull($response['dimensions']['dimensions_display']);
        $this->assertNull($response['dimensions']['weight_display']);
    }

    public function test_build_response_returns_available_dimension_and_trade_fields(): void
    {
        $linearUnit = new \App\Models\LinearUnit(['id' => 1, 'code' => 'in', 'name' => 'Inches']);
        $linearUnit->id = 1;

        $weightUnit = new \App\Models\WeightUnit(['id' => 2, 'code' => 'lb', 'name' => 'Pounds']);
        $weightUnit->id = 2;

        $product = new Product();
        $product->id = 7;
        $product->psm_code = 'PSM00007';
        $product->height = '10.50';
        $product->width = '20';
        $product->length = '30';
        $product->weight = '5.25';
        $product->linear_unit_id = 1;
        $product->weight_unit_id = 2;
        $product->country_of_origin = 'US';
        $product->iso_code_2 = 'US';
        $product->iso_code_3 = 'USA';
        $product->hsn_code = '85182100';
        $product->setRelation('linearUnit', $linearUnit);
        $product->setRelation('weightUnit', $weightUnit);

        $service = new InventoryMasterDataService();
        $response = $service->buildResponse($product, 99);

        $this->assertSame(7, $response['product_id']);
        $this->assertSame(99, $response['equipment_id']);
        $this->assertSame(10.5, $response['dimensions']['height']);
        $this->assertSame(20.0, $response['dimensions']['width']);
        $this->assertSame(30.0, $response['dimensions']['length']);
        $this->assertSame(5.25, $response['dimensions']['weight']);
        $this->assertSame('US', $response['country_of_origin']);
        $this->assertSame('US', $response['iso_code_2']);
        $this->assertSame('USA', $response['iso_code_3']);
        $this->assertSame('85182100', $response['hsn_code']);
        $this->assertSame('30 x 20 x 10.5 in', $response['dimensions']['dimensions_display']);
        $this->assertSame('5.25 lb', $response['dimensions']['weight_display']);
    }
}

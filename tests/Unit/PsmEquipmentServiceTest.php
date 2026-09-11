<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\InventoryMasterImage;
use App\Models\Product;
use App\Services\InventoryMaster\PsmEquipmentService;
use Tests\TestCase;

class PsmEquipmentServiceTest extends TestCase
{
    public function test_format_listing_item_returns_minimal_fields_and_primary_image(): void
    {
        $brand = new Brand(['name' => 'Shure']);
        $brand->id = 3;

        $image = new InventoryMasterImage([
            'image_path' => 'images/inventory_master/mic.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);
        $image->id = 9;

        $product = new Product([
            'model' => 'SM58',
            'psm_code' => 'PSM10001',
        ]);
        $product->id = 15;
        $product->setRelation('brand', $brand);
        $product->setRelation('primaryMasterImage', $image);

        $payload = (new PsmEquipmentService)->formatListingItem($product, true);

        $this->assertSame(15, $payload['id']);
        $this->assertSame('Shure SM58', $payload['name']);
        $this->assertSame('SM58', $payload['model']);
        $this->assertSame('PSM10001', $payload['psm_code']);
        $this->assertTrue($payload['already_in_company_inventory']);
        $this->assertTrue($payload['primary_image']['is_primary']);
        $this->assertStringContainsString('images/inventory_master/mic.jpg', $payload['primary_image']['url']);
        $this->assertArrayNotHasKey('height', $payload);
        $this->assertArrayNotHasKey('images', $payload);
    }

    public function test_format_details_includes_catalog_specs_and_images_without_inventing_description(): void
    {
        $product = new Product([
            'model' => 'SM58',
            'psm_code' => 'PSM10001',
            'replacement_price' => 120.5,
            'height' => 10,
            'width' => 5,
            'length' => 20,
            'weight' => 1.5,
            'country_of_origin' => 'CN',
            'hsn_code' => '85181000',
            'iso_code_2' => 'CN',
            'iso_code_3' => 'CHN',
        ]);
        $product->id = 15;
        $product->setRelation('brand', null);
        $product->setRelation('category', null);
        $product->setRelation('subCategory', null);
        $product->setRelation('linearUnit', null);
        $product->setRelation('weightUnit', null);
        $product->setRelation('masterImages', collect());

        $payload = (new PsmEquipmentService)->formatDetails($product);

        $this->assertSame(15, $payload['id']);
        $this->assertSame('SM58', $payload['name']);
        $this->assertSame(120.5, $payload['replacement_price']);
        $this->assertSame('CN', $payload['country_of_origin']);
        $this->assertSame('85181000', $payload['hsn_code']);
        $this->assertSame([], $payload['images']);
        $this->assertNull($payload['primary_image']);
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('rental_price', $payload);
        $this->assertArrayNotHasKey('flex_resource_id', $payload);
    }
}

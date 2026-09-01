<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\SupplyJobProduct;
use App\Services\HireTrackIntegrationService;
use App\Services\SupplierSmsNotifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class HireTrackIntegrationServiceTest extends TestCase
{
    public function test_is_hiretrack_software_matches_name_substring(): void
    {
        $this->assertTrue(HireTrackIntegrationService::isHireTrackSoftware('HireTrack'));
        $this->assertTrue(HireTrackIntegrationService::isHireTrackSoftware('HireTrack NX'));
        $this->assertTrue(HireTrackIntegrationService::isHireTrackSoftware('  hiretrack  '));
        $this->assertFalse(HireTrackIntegrationService::isHireTrackSoftware('Flex'));
        $this->assertFalse(HireTrackIntegrationService::isHireTrackSoftware('Rentman'));
        $this->assertFalse(HireTrackIntegrationService::isHireTrackSoftware(null));
        $this->assertFalse(HireTrackIntegrationService::isHireTrackSoftware(''));
        $this->assertFalse(HireTrackIntegrationService::isHireTrackSoftware('   '));
    }

    public function test_has_hiretrack_code_ignores_blank_values(): void
    {
        $this->assertTrue(HireTrackIntegrationService::hasHireTrackCode('HT-001'));
        $this->assertTrue(HireTrackIntegrationService::hasHireTrackCode('  HT-025  '));
        $this->assertFalse(HireTrackIntegrationService::hasHireTrackCode(null));
        $this->assertFalse(HireTrackIntegrationService::hasHireTrackCode(''));
        $this->assertFalse(HireTrackIntegrationService::hasHireTrackCode('   '));
    }

    public function test_build_txt_uses_software_code_and_quantity_without_header(): void
    {
        $txt = HireTrackIntegrationService::buildTxt([
            ['type' => 'AE5', 'quantity' => 6],
            ['type' => 'MME-1006', 'quantity' => 2],
            ['type' => 'MME-59', 'quantity' => 4],
            ['type' => 'MME-430', 'quantity' => 2],
            ['type' => 'MME-223', 'quantity' => 1],
        ]);

        $this->assertSame(
            "AE5,6\nMME-1006,2\nMME-59,4\nMME-430,2\nMME-223,1\n",
            $txt
        );
        $this->assertStringNotContainsString('TYPE', $txt);
        $this->assertStringNotContainsString('Quantity', $txt);
    }

    public function test_partition_includes_coded_products_and_skips_products_without_codes(): void
    {
        $codedProduct = $this->makeProduct(1, 'SM58 Microphone', 'Shure');
        $skippedProduct = $this->makeProduct(2, 'Mixer', 'Yamaha');
        $blankCodeProduct = $this->makeProduct(3, 'Cable', 'Generic');

        $lines = collect([
            $this->makeLine($codedProduct, 2),
            $this->makeLine($skippedProduct, 4),
            $this->makeLine($blankCodeProduct, 1),
        ]);

        $equipment = collect([
            1 => $this->makeEquipment(1, 'HT-001'),
            3 => $this->makeEquipment(3, '   '),
        ]);

        $partition = (new HireTrackIntegrationService())->partitionLines($lines, $equipment);

        $this->assertSame([
            ['type' => 'HT-001', 'quantity' => 2],
        ], $partition['included']);

        $this->assertSame([
            ['name' => 'Yamaha Mixer', 'quantity' => 4],
            ['name' => 'Generic Cable', 'quantity' => 1],
        ], $partition['skipped']);

        $txt = HireTrackIntegrationService::buildTxt($partition['included']);
        $this->assertStringContainsString('HT-001,2', $txt);
        $this->assertStringNotContainsString('Yamaha', $txt);
        $this->assertStringNotContainsString('Mixer', $txt);
        $this->assertStringNotContainsString('Cable', $txt);
    }

    public function test_partition_skips_all_products_when_none_have_hiretrack_codes(): void
    {
        $product = $this->makeProduct(10, 'SM58 Microphone', 'Shure');
        $lines = collect([$this->makeLine($product, 4)]);
        $equipment = new Collection();

        $partition = (new HireTrackIntegrationService())->partitionLines($lines, $equipment);

        $this->assertSame([], $partition['included']);
        $this->assertSame([
            ['name' => 'Shure SM58 Microphone', 'quantity' => 4],
        ], $partition['skipped']);
    }

    public function test_skipped_products_section_is_empty_when_none_are_skipped(): void
    {
        $this->assertSame('', HireTrackIntegrationService::skippedProductsSectionHtml([]));
    }

    public function test_skipped_products_section_lists_names_and_quantities(): void
    {
        $html = HireTrackIntegrationService::skippedProductsSectionHtml([
            ['name' => 'Shure SM58 Microphone', 'quantity' => 4],
            ['name' => 'Yamaha Mixer', 'quantity' => 1],
        ]);

        $this->assertStringContainsString('could not be included in the text file', $html);
        $this->assertStringContainsString('do not have a HireTrack rental software code', $html);
        $this->assertStringContainsString('Shure SM58 Microphone — Quantity: 4', $html);
        $this->assertStringContainsString('Yamaha Mixer — Quantity: 1', $html);
        $this->assertStringContainsString('Please review these items manually', $html);
    }

    public function test_format_date_for_provider_falls_back_when_no_date_format_is_configured(): void
    {
        $provider = new Company();
        $provider->date_format_id = null;

        $formatted = HireTrackIntegrationService::formatDateForProvider(
            Carbon::parse('2026-09-15'),
            $provider
        );

        $this->assertSame(
            Carbon::parse('2026-09-15')->format(SupplierSmsNotifier::getPhpDateFormat(null)),
            $formatted
        );
        $this->assertNotSame('2026-09-15', $formatted);
    }

    public function test_format_date_for_provider_returns_empty_string_for_missing_date(): void
    {
        $this->assertSame('', HireTrackIntegrationService::formatDateForProvider(null, new Company()));
        $this->assertSame('', HireTrackIntegrationService::formatDateForProvider('', null));
    }

    private function makeProduct(int $id, string $model, string $brandName): Product
    {
        $brand = new Brand();
        $brand->name = $brandName;

        $product = new Product();
        $product->id = $id;
        $product->model = $model;
        $product->setRelation('brand', $brand);

        return $product;
    }

    private function makeLine(Product $product, int $quantity): SupplyJobProduct
    {
        $line = new SupplyJobProduct();
        $line->product_id = $product->id;
        $line->required_quantity = $quantity;
        $line->offered_quantity = $quantity;
        $line->setRelation('product', $product);

        return $line;
    }

    private function makeEquipment(int $productId, ?string $softwareCode): Equipment
    {
        $equipment = new Equipment();
        $equipment->product_id = $productId;
        $equipment->software_code = $softwareCode;

        return $equipment;
    }
}

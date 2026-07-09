<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\InventoryMaster\InventoryMasterSpecSqlExportService;
use Tests\TestCase;

class InventoryMasterSpecSqlExportServiceTest extends TestCase
{
    public function test_sql_literal_handles_null_numbers_and_strings(): void
    {
        $service = new InventoryMasterSpecSqlExportService();

        $this->assertSame('NULL', $service->sqlLiteral(null));
        $this->assertSame('12', $service->sqlLiteral(12));
        $this->assertSame('12.5', $service->sqlLiteral(12.5));
        $this->assertSame('12.50', $service->sqlLiteral('12.50'));
        $this->assertSame("'O\\'Brien'", $service->sqlLiteral("O'Brien"));
        $this->assertSame("'line\\nbreak'", $service->sqlLiteral("line\nbreak"));
        $this->assertSame("'USA'", $service->sqlLiteral('USA'));
    }

    public function test_build_update_statement_includes_all_spec_fields(): void
    {
        $product = new Product();
        $product->id = 99;
        $product->length = 10.5;
        $product->width = 4;
        $product->height = null;
        $product->weight = '2.25';
        $product->linear_unit_id = 1;
        $product->weight_unit_id = 2;
        $product->country_of_origin = "Cote d'Ivoire";
        $product->hsn_code = '8471.30';

        $service = new InventoryMasterSpecSqlExportService();
        $sql = $service->buildUpdateStatement($product);

        $this->assertStringContainsString('UPDATE `inventory_master`', $sql);
        $this->assertStringContainsString('WHERE `id` = 99;', $sql);
        $this->assertStringContainsString('`length` = 10.5', $sql);
        $this->assertStringContainsString('`width` = 4', $sql);
        $this->assertStringContainsString('`height` = NULL', $sql);
        $this->assertStringContainsString('`weight` = 2.25', $sql);
        $this->assertStringContainsString('`linear_unit_id` = 1', $sql);
        $this->assertStringContainsString('`weight_unit_id` = 2', $sql);
        $this->assertStringContainsString("`country_of_origin` = 'Cote d\\'Ivoire'", $sql);
        $this->assertStringContainsString("`hsn_code` = '8471.30'", $sql);
        $this->assertStringNotContainsString('`hsn_code` = 8471.30', $sql);
    }

    public function test_string_fields_remain_quoted_even_when_numeric_looking(): void
    {
        $service = new InventoryMasterSpecSqlExportService();

        $this->assertSame("'8471.30'", $service->sqlLiteralForField('hsn_code', '8471.30'));
        $this->assertSame("'123'", $service->sqlLiteralForField('country_of_origin', '123'));
        $this->assertSame('NULL', $service->sqlLiteralForField('hsn_code', ''));
        $this->assertSame('NULL', $service->sqlLiteralForField('height', null));
        $this->assertSame('10.5', $service->sqlLiteralForField('height', 10.5));
        $this->assertSame('3', $service->sqlLiteralForField('linear_unit_id', '3'));
    }
}

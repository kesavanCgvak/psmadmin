<?php

namespace App\Services\InventoryMaster;

use App\Models\Product;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryMasterSpecEnrichment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class InventoryMasterSpecSqlExportService
{
    private const EXPORT_DIRECTORY = 'sql_exports';

    /** @var list<string> */
    private const EXPORT_FIELDS = CompanyInventorySpecs::FIELDS;

    /**
     * Read specification columns from inventory_master and write MySQL UPDATE statements to a SQL file.
     * Does not modify any database records.
     *
     * @return array{products_exported: int, filename: string, path: string, relative_path: string}
     */
    public function export(): array
    {
        $timestamp = now()->format('Y_m_d_H_i_s');
        $filename = "inventory_master_specifications_{$timestamp}.sql";
        $relativePath = self::EXPORT_DIRECTORY.'/'.$filename;

        Storage::disk('local')->makeDirectory(self::EXPORT_DIRECTORY);

        $fullPath = Storage::disk('local')->path($relativePath);
        $handle = fopen($fullPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open SQL export file for writing.');
        }

        $productsExported = 0;

        try {
            fwrite($handle, $this->buildHeader($timestamp));

            Product::query()
                ->select(array_merge(['id'], self::EXPORT_FIELDS))
                ->where(function ($query) {
                    foreach (self::EXPORT_FIELDS as $field) {
                        $query->orWhere(function ($fieldQuery) use ($field) {
                            $fieldQuery->whereNotNull($field)->where($field, '!=', '');
                        });
                    }
                })
                ->orderBy('id')
                ->chunkById(500, function ($products) use ($handle, &$productsExported) {
                    foreach ($products as $product) {
                        if (! $this->productHasExportableSpecs($product)) {
                            continue;
                        }

                        fwrite($handle, $this->buildUpdateStatement($product));
                        fwrite($handle, "\n");
                        $productsExported++;
                    }
                });

            fwrite($handle, "\n-- End of export ({$productsExported} products)\n");
        } catch (\Throwable $e) {
            fclose($handle);

            if (is_file($fullPath)) {
                @unlink($fullPath);
            }

            Log::error('InventoryMasterSpecSqlExportService: failed to generate SQL export', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        fclose($handle);

        Log::info('InventoryMasterSpecSqlExportService: SQL export generated', [
            'products_exported' => $productsExported,
            'filename' => $filename,
            'path' => $fullPath,
        ]);

        return [
            'products_exported' => $productsExported,
            'filename' => $filename,
            'path' => $fullPath,
            'relative_path' => $relativePath,
        ];
    }

    /** @var list<string> */
    private const STRING_FIELDS = [
        'country_of_origin',
        'hsn_code',
    ];

    /** @var list<string> */
    private const INTEGER_FIELDS = [
        'linear_unit_id',
        'weight_unit_id',
    ];

    public function buildUpdateStatement(Product $product): string
    {
        $assignments = [];

        foreach (self::EXPORT_FIELDS as $field) {
            $assignments[] = "    `{$field}` = ".$this->sqlLiteralForField($field, $product->{$field});
        }

        return "UPDATE `inventory_master`\n"
            ."SET\n"
            .implode(",\n", $assignments)."\n"
            .'WHERE `id` = '.(int) $product->id.';';
    }

    public function sqlLiteralForField(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        if (in_array($field, self::STRING_FIELDS, true)) {
            return $this->quoteString((string) $value);
        }

        if (in_array($field, self::INTEGER_FIELDS, true)) {
            return (string) (int) $value;
        }

        // height, width, length, weight
        return $this->sqlNumericLiteral($value);
    }

    /**
     * Generic literal helper used by tests and numeric fields.
     */
    public function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->formatFloat($value);
        }

        if (is_string($value) && $this->isUnquotedNumericString($value)) {
            return $value;
        }

        return $this->quoteString((string) $value);
    }

    private function sqlNumericLiteral(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->formatFloat($value);
        }

        if (is_string($value) && $this->isUnquotedNumericString($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $this->formatFloat((float) $value);
        }

        return 'NULL';
    }

    private function formatFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'NULL';
        }

        // Avoid locale-dependent decimal separators and scientific notation noise.
        $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private function productHasExportableSpecs(Product $product): bool
    {
        foreach (self::EXPORT_FIELDS as $field) {
            if (! InventoryMasterSpecEnrichment::isFieldEmpty($product->{$field})) {
                return true;
            }
        }

        return false;
    }

    private function buildHeader(string $timestamp): string
    {
        return "-- inventory_master specification export\n"
            ."-- Generated at: {$timestamp}\n"
            ."-- Source: inventory_master (read-only export; no DB modifications performed)\n"
            ."-- Review before applying to LIVE\n"
            ."SET NAMES utf8mb4;\n\n";
    }

    private function isUnquotedNumericString(string $value): bool
    {
        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    /**
     * MySQL string literal escaping compatible with utf8mb4 (backslash + quote escaping).
     */
    private function quoteString(string $value): string
    {
        $escaped = str_replace(
            ["\\", "\0", "\n", "\r", "'", '"', "\x1a"],
            ["\\\\", '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value
        );

        return "'{$escaped}'";
    }
}

<?php

namespace App\Support;

use App\Models\Product;

final class PsmCodeGenerator
{
    /**
     * Next sequential PSM code using the existing catalog numbering (PSM00001).
     * Matches the admin product-create algorithm: highest numeric part + 1.
     */
    public static function next(): string
    {
        try {
            $latestNumber = Product::query()
                ->whereNotNull('psm_code')
                ->selectRaw("
                    MAX(
                        CAST(
                            REGEXP_REPLACE(psm_code, '[^0-9]', '')
                        AS UNSIGNED)
                    ) AS max_number
                ")
                ->value('max_number');
        } catch (\Throwable) {
            $latestNumber = null;
        }

        if ($latestNumber === null) {
            $latestNumber = self::maxNumericPartFromPhp();
        }

        return 'PSM'.str_pad((string) (((int) $latestNumber) + 1), 5, '0', STR_PAD_LEFT);
    }

    public static function isAvailable(?string $psmCode): bool
    {
        $code = trim((string) $psmCode);
        if ($code === '') {
            return true;
        }

        return ! Product::query()->where('psm_code', $code)->exists();
    }

    private static function maxNumericPartFromPhp(): int
    {
        $max = 0;
        Product::query()
            ->whereNotNull('psm_code')
            ->select(['id', 'psm_code'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use (&$max) {
                foreach ($products as $product) {
                    if (preg_match('/(\d+)/', (string) $product->psm_code, $matches)) {
                        $max = max($max, (int) $matches[1]);
                    }
                }
            });

        return $max;
    }
}

<?php

namespace App\Support;

use App\Models\Product;

class PsmCodeGenerator
{
    /**
     * Generate the next sequential PSM code (PSM00001, PSM00002, ...).
     */
    public static function generateNext(): string
    {
        $latest = Product::select('psm_code')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($latest && preg_match('/PSM(\d+)/', $latest->psm_code, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        } else {
            $nextNumber = 1;
        }

        return 'PSM' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}

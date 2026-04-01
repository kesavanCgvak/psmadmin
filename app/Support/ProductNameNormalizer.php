<?php

namespace App\Support;

/**
 * Shared normalization for duplicate detection (create-or-attach, import confirm).
 * Aligns with Api\ProductController::normalizeProductName — word order agnostic, keeps digit tokens.
 */
class ProductNameNormalizer
{
    public static function normalize(string $productName): string
    {
        $normalized = strtolower($productName);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        $words = explode(' ', $normalized);
        sort($words);

        $commonWords = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by'];
        $words = array_filter($words, function ($word) use ($commonWords) {
            if (in_array($word, $commonWords, true)) {
                return false;
            }
            if ($word !== '' && ctype_digit($word)) {
                return true;
            }

            return strlen($word) > 1;
        });

        return implode(' ', $words);
    }

    /**
     * True when the two labels are the same catalog product for duplicate blocking.
     */
    public static function isSameProductLabel(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}

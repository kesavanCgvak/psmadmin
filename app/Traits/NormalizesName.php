<?php

namespace App\Traits;

trait NormalizesName
{
    /**
     * Normalize a name for duplicate comparison.
     * - Convert to lowercase
     * - Trim whitespace
     * - Normalize common symbols and special characters
     * 
     * @param string $name
     * @return string
     */
    public static function normalizeName(string $name): string
    {
        // Convert to lowercase and trim
        $normalized = strtolower(trim($name));
        
        // Normalize common symbols and special characters
        // Replace multiple spaces with single space
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Normalize common punctuation and symbols
        $normalized = str_replace(['-', '_', '.', ',', ';', ':', '!', '?', '(', ')', '[', ']', '{', '}'], '', $normalized);
        
        // Remove accents and diacritics (optional - can be enabled if needed)
        // $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        
        return $normalized;
    }
}


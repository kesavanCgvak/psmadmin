<?php

namespace App\Traits;

trait NormalizesName
{
    /**
     * Normalize a name for duplicate comparison
     * - Case-insensitive
     * - Trim spaces
     * - Remove symbols (keep only alphanumeric and spaces)
     * 
     * @param string $name
     * @return string
     */
    protected function normalizeName(string $name): string
    {
        return self::normalizeNameStatic($name);
    }

    /**
     * Static version of normalizeName for use in static contexts
     * - Case-insensitive
     * - Trim spaces
     * - Remove symbols (keep only alphanumeric and spaces)
     * 
     * @param string $name
     * @return string
     */
    protected static function normalizeNameStatic(string $name): string
    {
        // Convert to lowercase
        $normalized = strtolower($name);
        
        // Remove all non-alphanumeric characters except spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        
        // Trim and normalize whitespace (multiple spaces to single space)
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        
        return $normalized;
    }
}

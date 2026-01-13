<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProductNormalizer
{
    /**
     * Normalize product codes/model numbers by removing all non-alphanumeric characters
     * Examples: "DML-1122" -> "dml1122", "DML 1122" -> "dml1122", "SSM" -> "ssm"
     *
     * @param string|null $value
     * @return string|null
     */
    public static function normalizeCode(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = Str::lower(trim($value));
        // Remove everything except letters and digits
        $value = preg_replace('/[^a-z0-9]/', '', $value);

        return $value ?: null;
    }

    /**
     * Normalize full product name (brand + model) by removing special characters and metadata
     * Examples: "Apogee SSM -" -> "apogeessm", "EV-DML1122" -> "evdml1122"
     *
     * @param string|null $brand
     * @param string|null $model
     * @return string|null
     */
    public static function normalizeFullName(?string $brand, ?string $model): ?string
    {
        $full = trim(($brand ? $brand . ' ' : '') . ($model ?? ''));

        if ($full === '') {
            return null;
        }

        $full = Str::lower($full);
        
        // Strip bracket/parenthesis metadata like [Amazon], (Fox), trailing hyphens
        $full = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $full);
        $full = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $full);
        
        // Remove trailing/leading hyphens and spaces
        $full = preg_replace('/^[\s\-]+|[\s\-]+$/', '', $full);
        
        // Remove non-alphanumeric but keep spaces temporarily
        $full = preg_replace('/[^a-z0-9\s]/', ' ', $full);
        
        // Normalize whitespace
        $full = preg_replace('/\s+/', ' ', trim($full));

        // Final canonical value for DB: remove spaces entirely for exact matching
        return preg_replace('/\s+/', '', $full) ?: null;
    }

    /**
     * Extract model code from a description (e.g., "EV DML1122 Speaker" -> "DML1122")
     * Handles formats like: DML-1122, DML1122, DML 1122, DN-360, EOS R5, SSM, M-267
     *
     * @param string $description
     * @return string|null
     */
    public static function extractModelCode(string $description): ?string
    {
        // Pattern 1: Single letter + separator + digits (e.g., "M-267", "A-123", "X-500")
        // This handles cases like "M-267 4-Channel" -> "M-267"
        if (preg_match('/\b([A-Z][-\s]\d{1,6})\b/i', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 2: Single letter directly followed by digits (e.g., "M267", "A123")
        if (preg_match('/\b([A-Z]\d{2,6})\b/i', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 3: 2-10 letters + optional separator + 1-6 digits
        // Matches: DML1122, DML-1122, DML 1122, DN-360, EOS R5, SSM (if followed by space/end)
        if (preg_match('/\b([A-Z]{2,10}[-\s]?\d{1,6})\b/i', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 4: Shorter patterns for codes like "SSM", "R2", "X1" (2-5 letters + 1-3 digits)
        if (preg_match('/\b([A-Z]{2,5}[-\s]?\d{1,3})\b/i', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Normalize a description string for matching (removes metadata, normalizes format)
     *
     * @param string $description
     * @return string
     */
    public static function normalizeDescription(string $description): string
    {
        $text = Str::lower(trim($description));
        
        // Remove bracketed content (metadata like [Amazon], [Fox])
        $text = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $text);
        
        // Remove parenthesized content that looks like metadata
        $text = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $text);
        
        // Normalize brand names - common variations
        $text = preg_replace('/\bklark[-\s]?teknik\b/i', 'klarkteknik', $text);
        $text = preg_replace('/\bkt\b/i', 'klarkteknik', $text);
        
        // Normalize common abbreviations
        $text = preg_replace('/\bprofessional\b/i', 'pro', $text);
        $text = preg_replace('/\bequalizer\b/i', 'eq', $text);
        
        // Remove special characters but keep spaces
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        return $text;
    }

    /**
     * Check if a normalized code is valid (minimum length to avoid false positives)
     *
     * @param string|null $normalizedCode
     * @return bool
     */
    public static function isValidNormalizedCode(?string $normalizedCode): bool
    {
        if (!$normalizedCode) {
            return false;
        }

        // Require at least 2 characters to avoid matching single letters/numbers
        return strlen($normalizedCode) >= 2;
    }
}



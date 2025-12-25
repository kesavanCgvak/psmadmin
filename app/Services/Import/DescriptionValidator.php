<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;

class DescriptionValidator
{
    /**
     * Comprehensive validation to reject gibberish and ensure meaningful product descriptions
     *
     * @param string $description
     * @throws ValidationException
     */
    public function validateDescription(string $description): void
    {
        $errors = [];

        // 1. Minimum length check (increased from 5 to 10 for better quality)
        if (strlen(trim($description)) < 10) {
            $errors[] = 'Description must be at least 10 characters long';
            $this->throwIfErrors($errors);
        }

        // 2. Maximum length check (prevent abuse)
        if (strlen(trim($description)) > 200) {
            $errors[] = 'Description exceeds maximum length of 200 characters';
            $this->throwIfErrors($errors);
        }

        // 3. Model number is OPTIONAL - products can be matched by description/text even without model numbers
        // We'll still try to extract model numbers if present, but it's not required

        // 4. Gibberish detection - repetitive characters (e.g., "AAAAA 123", "XXXXX 999")
        if ($this->hasRepetitiveChars($description)) {
            $errors[] = 'Description appears to be gibberish (repetitive characters detected)';
            $this->throwIfErrors($errors);
        }

        // 5. Gibberish detection - random typing patterns (e.g., "QWERTY 123", "ASDFG 456")
        if ($this->hasRandomPattern($description)) {
            $errors[] = 'Description does not appear to be a valid product name (random character pattern detected)';
            $this->throwIfErrors($errors);
        }

        // 6. Must contain at least 2 distinct words
        $words = array_filter(array_map('trim', explode(' ', trim($description))));
        if (count($words) < 2) {
            $errors[] = 'Description must contain at least 2 words';
            $this->throwIfErrors($errors);
        }

        // 7. Meaningful content check - must have substantial words (model number NOT required)
        if (!$this->hasMeaningfulContent($description)) {
            $errors[] = 'Description must contain meaningful product information';
            $this->throwIfErrors($errors);
        }
    }

    /**
     * Check for repetitive characters or words
     */
    protected function hasRepetitiveChars(string $text): bool
    {
        // Check for 4+ consecutive identical characters (e.g., "AAAAA", "XXXXX")
        if (preg_match('/(.)\1{3,}/i', $text)) {
            return true;
        }

        // Check for repetitive word patterns
        $words = array_filter(explode(' ', $text));
        if (count($words) >= 2) {
            $uniqueWords = array_unique($words);
            // If more than 50% of words are duplicates, it's likely gibberish
            if (count($uniqueWords) < count($words) * 0.5) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect random typing patterns (QWERTY keyboard patterns)
     * ✅ UPDATED: Allows sequential/repeating numbers in legitimate product codes
     */
    protected function hasRandomPattern(string $text): bool
    {
        // ✅ Extract potential model codes (letters followed by numbers, e.g., "DML1122", "EV-1122")
        // This helps distinguish legitimate product codes from random patterns
        $hasLegitimateModelCode = $this->hasLegitimateModelCode($text);

        // Remove numbers and special characters for pattern checking
        $letters = preg_replace('/[^A-Za-z]/', '', $text);

        // Check for QWERTY-like sequences (definite random pattern)
        $qwertyPatterns = ['qwerty', 'asdf', 'zxcv', 'hjkl', 'uiop'];
        foreach ($qwertyPatterns as $pattern) {
            if (stripos($letters, $pattern) !== false) {
                return true;
            }
        }

        // ✅ IMPROVED: Check for too many consecutive consonants
        // More than 7 consecutive consonants is suspicious (increased from 6 to allow codes like "DML1122")
        // But if we have a legitimate model code, be more lenient
        $consonantThreshold = $hasLegitimateModelCode ? 8 : 6;
        if (preg_match('/[bcdfghjklmnpqrstvwxyz]{' . $consonantThreshold . ',}/i', $letters)) {
            // ✅ Exception: Allow common product-related prefixes/abbreviations
            $allowedPrefixes = ['dml', 'led', 'kva', 'rgb', 'dmx', 'xlr', 'trs', 'midi', 'usb', 'hdmi', 'sdi'];
            $lowerText = strtolower($text);
            foreach ($allowedPrefixes as $prefix) {
                if (stripos($lowerText, $prefix) !== false) {
                    return false; // Contains legitimate prefix, allow it
                }
            }
            return true;
        }

        return false;
    }

    /**
     * ✅ NEW: Check if text contains a legitimate model code pattern
     * Legitimate model codes typically have: letters + numbers (e.g., "DML1122", "EV-1122", "4455A")
     * This allows sequential/repeating numbers when they're part of a valid product code structure
     *
     * @param string $text The description text to check
     * @return bool True if a legitimate model code pattern is found
     */
    protected function hasLegitimateModelCode(string $text): bool
    {
        // Pattern 1: Letters directly followed by digits (most common: "DML1122", "EV1122", "DMX512")
        // Matches 1-6 letters followed by 2-8 digits (allows sequential/repeating numbers)
        // Examples: "DML1122", "EV1122", "DMX512", "LED4455", "RGB4455"
        if (preg_match('/\b[A-Z]{1,6}\d{2,8}\b/i', $text)) {
            return true;
        }

        // Pattern 2: Letters with hyphen or space separator, then digits
        // Examples: "EV-1122", "EV 1122", "DML-4455", "DMX-512"
        if (preg_match('/\b[A-Z]{1,6}[-\s]\d{2,8}\b/i', $text)) {
            return true;
        }

        // Pattern 3: Digits followed by letters (less common but valid)
        // Examples: "1122A", "4455SP", "1234XLR", "512DMX"
        if (preg_match('/\b\d{2,8}[A-Z]{1,4}\b/i', $text)) {
            return true;
        }

        // Pattern 4: Combined code - Letters, digits, then optional letters suffix
        // Examples: "DML1122SP", "EV4455LR", "DMX512A"
        if (preg_match('/\b[A-Z]{2,6}\d{2,8}[A-Z]{0,4}\b/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check for meaningful content (not just random letters/numbers)
     */
    protected function hasMeaningfulContent(string $text): bool
    {
        // Must have at least one word longer than 3 characters
        // Include alphanumeric words like "3kva", "12X2", etc.
        $words = array_filter(array_map('trim', explode(' ', trim($text))));
        // Filter words that are longer than 3 characters (including alphanumeric like "3kva", "12X2")
        // Remove punctuation/commas from word length check
        $longWords = array_filter($words, function($w) {
            // Remove punctuation for length check
            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
            return strlen($clean) > 3;
        });

        if (count($longWords) === 0) {
            return false;
        }

        // Check for common product-related keywords (helps validate it's a real product)
        $productKeywords = [
            'professional', 'equalizer', 'camera', 'lens', 'microphone',
            'mixer', 'amplifier', 'speaker', 'monitor', 'controller',
            'processor', 'converter', 'interface', 'recorder', 'player',
            'graphic', 'parametric', 'digital', 'analog', 'wireless',
            'system', 'unit', 'device', 'equipment', 'gear'
        ];

        $lowerText = strtolower($text);
        foreach ($productKeywords as $keyword) {
            if (str_contains($lowerText, $keyword)) {
                return true; // Contains product-related term
            }
        }

        // If it has a model number and at least one meaningful word, it's probably OK
        $hasModel = preg_match('/\b[A-Z]{1,5}[-\s]?\d{1,5}\b/i', $text);

        // If it has a model number, it's valid
        if ($hasModel && count($longWords) >= 1) {
            return true;
        }

        // Even without model number, if it has meaningful words (3+ long words), it's probably valid
        // This allows products like "Robe LEDBeam 150Movinghead" or "MA light Digital Dimmer 12X2"
        if (count($longWords) >= 3) {
            return true;
        }

        // If it has at least 2 long words and contains product keywords, it's valid
        if (count($longWords) >= 2) {
            // Check if any product keywords are present
            $lowerText = strtolower($text);
            $productKeywords = [
                'professional', 'equalizer', 'camera', 'lens', 'microphone',
                'mixer', 'amplifier', 'speaker', 'monitor', 'controller',
                'processor', 'converter', 'interface', 'recorder', 'player',
                'graphic', 'parametric', 'digital', 'analog', 'wireless',
                'system', 'unit', 'device', 'equipment', 'gear', 'dimmer',
                'beam', 'fixture', 'movinghead', 'led', 'light', 'kva',
                'chauvet', 'robe', 'ma', 'moving', 'head'
            ];

            foreach ($productKeywords as $keyword) {
                if (str_contains($lowerText, $keyword)) {
                    return true;
                }
            }
        }

        // Default: require at least 1 long word for meaningful content.
        // This allows concise names like "Apogee SSM" (brand + model) to pass validation.
        // More aggressive gibberish checks are already handled earlier.
        return count($longWords) >= 1;
    }

    /**
     * Throw validation exception if errors exist
     */
    protected function throwIfErrors(array $errors): void
    {
        if (!empty($errors)) {
            throw ValidationException::withMessages([
                'description' => $errors,
            ]);
        }
    }
}


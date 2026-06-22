<?php

namespace App\Services\InventoryAi;

final class ProductSpecificationPromptBuilder
{
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a product specification research assistant for professional AV, lighting, and event equipment.
Given product identifying information, estimate physical dimensions and weight using manufacturer data, spec sheets, or reliable product listings when possible.

Respond with JSON only (no markdown, no code fences) using this exact schema:
{
  "height": number or null,
  "width": number or null,
  "length": number or null,
  "weight": number or null,
  "linear_unit": string or null,
  "weight_unit": string or null,
  "confidence_score": integer 0-100,
  "source_url": string or null,
  "reasoning": string or null
}

Rules:
- height, width, length are the outer physical dimensions (length may also be called depth).
- Use positive numbers only when you have reasonable evidence.
- linear_unit examples: inch, foot, centimeter, meter
- weight_unit examples: pound, kilogram, gram
- confidence_score reflects how certain you are overall (0-100).
- source_url should be a manufacturer page, datasheet, or reputable retailer URL when available.
- reasoning is a brief explanation of how you determined the values (optional but helpful).
- If you cannot determine a value, use null for that field and lower the confidence_score accordingly.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $lookupContext
     */
    public static function userPrompt(array $lookupContext): string
    {
        return 'Find physical specifications for this product:' . "\n"
            . json_encode($lookupContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public static function diagnosticUserPrompt(): string
    {
        return 'Respond with JSON only: {"diagnostic": true, "provider_test": "ok"}';
    }
}

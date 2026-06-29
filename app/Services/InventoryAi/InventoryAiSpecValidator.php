<?php

namespace App\Services\InventoryAi;

use App\Support\InventoryMasterSpecEnrichment;
use App\Support\InventoryMeasurementUnits;

final class InventoryAiSpecValidator
{
    /** @var list<string> */
    private const DIMENSION_FIELDS = ['height', 'width', 'length'];

    /**
     * @param  array<string, mixed>  $aiParsed
     * @param  list<string>  $missingProductFields
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     mapped: array<string, mixed>,
     *     fills_missing: list<string>,
     *     no_values_determined: bool
     * }
     */
    public function validate(array $aiParsed, array $missingProductFields): array
    {
        $errors = [];
        $mapped = [
            'height' => null,
            'width' => null,
            'length' => null,
            'weight' => null,
            'linear_unit_id' => null,
            'weight_unit_id' => null,
            'confidence_score' => null,
            'source_url' => null,
        ];

        if (!array_key_exists('confidence_score', $aiParsed) || $aiParsed['confidence_score'] === null || $aiParsed['confidence_score'] === '') {
            $errors[] = 'confidence_score is required.';
        } else {
            $confidence = (int) $aiParsed['confidence_score'];
            if ($confidence < 0 || $confidence > 100) {
                $errors[] = 'confidence_score must be between 0 and 100.';
            } else {
                $mapped['confidence_score'] = $confidence;
            }
        }

        $sourceUrl = $aiParsed['source_url'] ?? null;
        if (is_string($sourceUrl) && trim($sourceUrl) !== '') {
            $mapped['source_url'] = trim($sourceUrl);
        }

        foreach (self::DIMENSION_FIELDS as $field) {
            if (!in_array($field, $missingProductFields, true)) {
                continue;
            }

            $value = $aiParsed[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value) || (float) $value <= 0) {
                $errors[] = "{$field} must be greater than zero.";

                continue;
            }

            $mapped[$field] = round((float) $value, 2);
        }

        if (in_array('weight', $missingProductFields, true)) {
            $weight = $aiParsed['weight'] ?? null;
            if ($weight !== null && $weight !== '') {
                if (!is_numeric($weight) || (float) $weight <= 0) {
                    $errors[] = 'weight must be greater than zero.';
                } else {
                    $mapped['weight'] = round((float) $weight, 2);
                }
            }
        }

        if (in_array('linear_unit_id', $missingProductFields, true)) {
            $linearUnit = $aiParsed['linear_unit'] ?? $aiParsed['linear_unit_code'] ?? $aiParsed['linear_unit_name'] ?? null;
            if (is_string($linearUnit) && trim($linearUnit) !== '') {
                $linearUnitId = InventoryMeasurementUnits::resolveLinearUnitIdByCodeOrName($linearUnit);
                if ($linearUnitId === null) {
                    $errors[] = 'linear_unit is not a recognized system unit.';
                } else {
                    $mapped['linear_unit_id'] = $linearUnitId;
                }
            }
        }

        if (in_array('weight_unit_id', $missingProductFields, true)) {
            $weightUnit = $aiParsed['weight_unit'] ?? $aiParsed['weight_unit_code'] ?? $aiParsed['weight_unit_name'] ?? null;
            if (is_string($weightUnit) && trim($weightUnit) !== '') {
                $weightUnitId = InventoryMeasurementUnits::resolveWeightUnitIdByCodeOrName($weightUnit);
                if ($weightUnitId === null) {
                    $errors[] = 'weight_unit is not a recognized system unit.';
                } else {
                    $mapped['weight_unit_id'] = $weightUnitId;
                }
            }
        }

        $fillsMissing = [];
        foreach (InventoryMasterSpecEnrichment::SPEC_FIELDS as $field) {
            if (in_array($field, $missingProductFields, true) && $mapped[$field] !== null) {
                $fillsMissing[] = $field;
            }
        }

        $fillsAnyDimension = !empty(array_intersect($fillsMissing, self::DIMENSION_FIELDS));
        if ($fillsAnyDimension
            && in_array('linear_unit_id', $missingProductFields, true)
            && $mapped['linear_unit_id'] === null
        ) {
            $errors[] = 'linear_unit is required when dimension values are provided.';
        }

        if (in_array('weight', $fillsMissing, true)
            && in_array('weight_unit_id', $missingProductFields, true)
            && $mapped['weight_unit_id'] === null
        ) {
            $errors[] = 'weight_unit is required when weight is provided.';
        }

        $noValuesDetermined = $fillsMissing === [] && self::aiProvidedNoSpecValues($aiParsed);

        if ($fillsMissing === []) {
            $errors[] = 'No specification values could be determined from the AI response.';
        }

        return [
            'valid' => $errors === [] && $fillsMissing !== [],
            'errors' => $errors,
            'mapped' => $mapped,
            'fills_missing' => $fillsMissing,
            'no_values_determined' => $noValuesDetermined,
        ];
    }

    /**
     * @param  array<string, mixed>  $aiParsed
     */
    private static function aiProvidedNoSpecValues(array $aiParsed): bool
    {
        foreach ([
            'height',
            'width',
            'length',
            'weight',
            'linear_unit',
            'linear_unit_code',
            'linear_unit_name',
            'weight_unit',
            'weight_unit_code',
            'weight_unit_name',
        ] as $key) {
            $value = $aiParsed[$key] ?? null;

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values  height, width, length, weight, linear_unit_id, weight_unit_id
     * @param  list<string>  $missingProductFields
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateManualApprovalValues(array $values, array $missingProductFields): array
    {
        $errors = [];

        foreach (self::DIMENSION_FIELDS as $field) {
            if (!in_array($field, $missingProductFields, true)) {
                continue;
            }

            $value = $values[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value) || (float) $value <= 0) {
                $errors[] = "{$field} must be greater than zero.";
            }
        }

        if (in_array('weight', $missingProductFields, true)) {
            $weight = $values['weight'] ?? null;
            if ($weight !== null && $weight !== '') {
                if (!is_numeric($weight) || (float) $weight <= 0) {
                    $errors[] = 'weight must be greater than zero.';
                }
            }
        }

        $hasDimensionValue = false;
        foreach (self::DIMENSION_FIELDS as $field) {
            if (in_array($field, $missingProductFields, true)
                && isset($values[$field])
                && $values[$field] !== null
                && $values[$field] !== ''
            ) {
                $hasDimensionValue = true;
                break;
            }
        }

        if ($hasDimensionValue
            && in_array('linear_unit_id', $missingProductFields, true)
            && empty($values['linear_unit_id'])
        ) {
            $errors[] = 'linear_unit is required when dimension values are provided.';
        }

        $hasWeightValue = in_array('weight', $missingProductFields, true)
            && isset($values['weight'])
            && $values['weight'] !== null
            && $values['weight'] !== '';

        if ($hasWeightValue
            && in_array('weight_unit_id', $missingProductFields, true)
            && empty($values['weight_unit_id'])
        ) {
            $errors[] = 'weight_unit is required when weight is provided.';
        }

        $fillsAny = false;
        foreach (InventoryMasterSpecEnrichment::SPEC_FIELDS as $field) {
            if (in_array($field, $missingProductFields, true)
                && isset($values[$field])
                && $values[$field] !== null
                && $values[$field] !== ''
            ) {
                $fillsAny = true;
                break;
            }
        }

        if (!$fillsAny) {
            $errors[] = 'At least one specification value must be provided for approval.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}

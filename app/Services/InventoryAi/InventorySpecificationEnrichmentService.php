<?php

namespace App\Services\InventoryAi;

use App\Models\InventoryMasterAiLog;
use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use App\Support\InventoryMasterSpecEnrichment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventorySpecificationEnrichmentService
{
    public function __construct(
        private readonly ProductSpecificationAiClient $aiClient,
        private readonly InventoryAiSpecValidator $validator,
    ) {
    }

    /**
     * @return array{status: string, spec_id: int|null, message: string}
     */
    public function enrichProduct(int $inventoryMasterId): array
    {
        $product = Product::query()
            ->with(['brand:id,name', 'category:id,name', 'subCategory:id,name'])
            ->find($inventoryMasterId);

        if (!$product) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Product not found.',
            ];
        }

        if (InventoryMasterSpecEnrichment::hasCompletePhysicalSpecs($product)) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Product already has complete physical specifications.',
            ];
        }

        if (InventoryMasterSpecEnrichment::hasPendingEnrichment($inventoryMasterId)) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'A pending enrichment request already exists for this product.',
            ];
        }

        if (!InventoryMasterSpecEnrichment::hasSufficientLookupInformation($product)) {
            $alreadyMarked = InventoryMasterAiSpec::query()
                ->where('inventory_master_id', $inventoryMasterId)
                ->where('status', InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION)
                ->exists();

            if ($alreadyMarked) {
                return [
                    'status' => 'skipped',
                    'spec_id' => null,
                    'message' => 'Product already marked as insufficient information.',
                ];
            }

            $spec = $this->createStagingRecord($product, [
                'status' => InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION,
                'ai_response' => [
                    'reason' => 'Product name (model) is required for AI lookup.',
                ],
            ]);

            Log::info('Inventory AI enrichment skipped: insufficient information.', [
                'inventory_master_id' => $inventoryMasterId,
                'spec_id' => $spec->id,
            ]);

            return [
                'status' => InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION,
                'spec_id' => $spec->id,
                'message' => 'Insufficient product information for lookup.',
            ];
        }

        $missingFields = InventoryMasterSpecEnrichment::missingSpecFields($product);
        $lookupContext = InventoryMasterSpecEnrichment::buildLookupContext($product);

        try {
            $aiResult = $this->aiClient->enrich($lookupContext);
        } catch (Throwable $e) {
            Log::error('Inventory AI enrichment request error.', [
                'inventory_master_id' => $inventoryMasterId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $parsed = $aiResult['parsed'];
        $validation = $this->validator->validate($parsed, $missingFields);
        $mapped = $validation['mapped'];

        $spec = $this->createStagingRecord($product, [
            'height' => $mapped['height'],
            'width' => $mapped['width'],
            'length' => $mapped['length'],
            'weight' => $mapped['weight'],
            'linear_unit_id' => $mapped['linear_unit_id'],
            'weight_unit_id' => $mapped['weight_unit_id'],
            'confidence_score' => $mapped['confidence_score'],
            'source_url' => $mapped['source_url'],
            'ai_response' => [
                'parsed' => $parsed,
                'validation_errors' => $validation['errors'],
                'raw' => $aiResult['raw_response'],
            ],
            'status' => InventoryMasterAiSpec::STATUS_PENDING,
        ]);

        if (!$validation['valid']) {
            $spec->update(['status' => InventoryMasterAiSpec::STATUS_REJECTED]);

            Log::info('Inventory AI enrichment rejected due to validation failure.', [
                'inventory_master_id' => $inventoryMasterId,
                'spec_id' => $spec->id,
                'errors' => $validation['errors'],
            ]);

            return [
                'status' => InventoryMasterAiSpec::STATUS_REJECTED,
                'spec_id' => $spec->id,
                'message' => 'Validation failed: ' . implode(' ', $validation['errors']),
            ];
        }

        $confidence = (int) $mapped['confidence_score'];
        $finalStatus = $this->resolveStatusFromConfidence($confidence);

        if ($finalStatus === InventoryMasterAiSpec::STATUS_APPROVED) {
            $this->approveAndApply($spec, $product, InventoryMasterAiLog::UPDATED_BY_AI);

            return [
                'status' => InventoryMasterAiSpec::STATUS_APPROVED,
                'spec_id' => $spec->id,
                'message' => 'Auto-approved and applied to inventory_master.',
            ];
        }

        $spec->update(['status' => $finalStatus]);

        Log::info('Inventory AI enrichment staged for review or rejected.', [
            'inventory_master_id' => $inventoryMasterId,
            'spec_id' => $spec->id,
            'status' => $finalStatus,
            'confidence_score' => $confidence,
        ]);

        return [
            'status' => $finalStatus,
            'spec_id' => $spec->id,
            'message' => $finalStatus === InventoryMasterAiSpec::STATUS_PENDING
                ? 'Staged for manual review.'
                : 'Rejected due to low confidence score.',
        ];
    }

    public function approveAndApply(
        InventoryMasterAiSpec $spec,
        ?Product $product = null,
        string $updatedBy = InventoryMasterAiLog::UPDATED_BY_MANUAL,
    ): void {
        $product ??= Product::query()->findOrFail($spec->inventory_master_id);

        DB::transaction(function () use ($spec, $product, $updatedBy) {
            $updates = [];

            foreach (InventoryMasterSpecEnrichment::SPEC_FIELDS as $field) {
                if (!InventoryMasterSpecEnrichment::isFieldEmpty($product->{$field})) {
                    continue;
                }

                $newValue = $spec->{$field};
                if (InventoryMasterSpecEnrichment::isFieldEmpty($newValue)) {
                    continue;
                }

                $oldValue = $product->{$field};
                $updates[$field] = $newValue;

                InventoryMasterAiLog::create([
                    'inventory_master_id' => $product->id,
                    'field_name' => $field,
                    'old_value' => $this->stringifyValue($oldValue),
                    'new_value' => $this->stringifyValue($newValue),
                    'confidence_score' => $spec->confidence_score,
                    'source_url' => $spec->source_url,
                    'updated_by' => $updatedBy,
                    'created_at' => now(),
                ]);
            }

            if ($updates !== []) {
                $product->update($updates);
            }

            $spec->update(['status' => InventoryMasterAiSpec::STATUS_APPROVED]);
        });
    }

    private function resolveStatusFromConfidence(int $confidence): string
    {
        $autoApprove = (int) config('inventory_ai.auto_approve_threshold', 75);
        $pendingMin = (int) config('inventory_ai.pending_min_threshold', 60);
        $pendingMax = (int) config('inventory_ai.pending_max_threshold', 74);
        $rejectThreshold = (int) config('inventory_ai.reject_threshold', 50);

        if ($confidence >= $autoApprove) {
            return InventoryMasterAiSpec::STATUS_APPROVED;
        }

        if ($confidence >= $pendingMin && $confidence <= $pendingMax) {
            return InventoryMasterAiSpec::STATUS_PENDING;
        }

        if ($confidence < $rejectThreshold) {
            return InventoryMasterAiSpec::STATUS_REJECTED;
        }

        return InventoryMasterAiSpec::STATUS_REJECTED;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createStagingRecord(Product $product, array $attributes): InventoryMasterAiSpec
    {
        return InventoryMasterAiSpec::create(array_merge([
            'inventory_master_id' => $product->id,
        ], $attributes));
    }

    private function stringifyValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}

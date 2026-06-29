<?php

namespace App\Services\InventoryAi;

use App\Models\InventoryMasterAiLog;
use App\Models\InventoryMasterAiRejection;
use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use App\Support\InventoryMasterSpecEnrichment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class InventorySpecificationEnrichmentService
{
    public function __construct(
        private readonly ProductSpecificationAiClient $aiClient,
        private readonly InventoryAiSpecValidator $validator,
        private readonly InventoryAiRejectionService $rejectionService,
    ) {
    }

    /**
     * @return array{status: string, spec_id: int|null, message: string}
     */
    public function enrichProduct(
        int $inventoryMasterId,
        bool $retryIncomplete = false,
        bool $bypassRejectionCheck = false,
        ?string $batchRunId = null,
    ): array {
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

        if (!$bypassRejectionCheck && $this->rejectionService->isRejected($inventoryMasterId)) {
            Log::info('Inventory AI enrichment skipped: previously rejected product.', [
                'inventory_master_id' => $inventoryMasterId,
                'batch_run_id' => $batchRunId,
            ]);

            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Skipped because this product was previously rejected.',
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

        if (!$retryIncomplete && InventoryMasterSpecEnrichment::hasApprovedPartialEnrichment($product)) {
            $reopened = InventoryMasterSpecEnrichment::reopenLatestApprovedSpecForReview($inventoryMasterId);

            Log::info('Inventory AI partial approved enrichment re-opened for manual review.', [
                'inventory_master_id' => $inventoryMasterId,
                'spec_id' => $reopened?->id,
                'missing_fields' => InventoryMasterSpecEnrichment::missingSpecFields($product),
            ]);

            return [
                'status' => InventoryMasterAiSpec::STATUS_PENDING,
                'spec_id' => $reopened?->id,
                'message' => 'Partial auto-approval re-opened for manual review. Complete remaining fields in admin.',
            ];
        }

        if (!$retryIncomplete && InventoryMasterSpecEnrichment::hasApprovedEnrichment($inventoryMasterId)) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Product already has an approved AI enrichment record.',
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

            $this->rejectionService->recordForProduct(
                $product,
                'Product name (model) is required for AI lookup.',
                InventoryMasterAiRejection::CATEGORY_INSUFFICIENT,
                $batchRunId,
                $spec->id,
            );

            return [
                'status' => InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION,
                'spec_id' => $spec->id,
                'message' => 'Insufficient product information for lookup.',
            ];
        }

        $missingFields = InventoryMasterSpecEnrichment::missingSpecFields($product);
        $lookupContext = InventoryMasterSpecEnrichment::buildLookupContext($product);

        if (!$retryIncomplete) {
            $alreadyInsufficient = InventoryMasterAiSpec::query()
                ->where('inventory_master_id', $inventoryMasterId)
                ->where('status', InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION)
                ->exists();

            if ($alreadyInsufficient) {
                return [
                    'status' => 'skipped',
                    'spec_id' => null,
                    'message' => 'Product already marked as insufficient information.',
                ];
            }
        }

        try {
            $aiResult = $this->aiClient->enrich($lookupContext);
        } catch (Throwable $e) {
            Log::error('Inventory AI enrichment request error.', [
                'inventory_master_id' => $inventoryMasterId,
                'provider' => AiProviderFactory::activeProviderName(),
                'error' => $e->getMessage(),
                'error_category' => $e instanceof \App\Services\InventoryAi\Exceptions\AiProviderException ? $e->category : null,
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
                'provider' => $aiResult['provider'] ?? AiProviderFactory::activeProviderName(),
                'model' => $aiResult['model'] ?? null,
                'parsed' => $parsed,
                'validation_errors' => $validation['errors'],
                'raw' => $aiResult['raw_response'],
            ],
            'status' => InventoryMasterAiSpec::STATUS_PENDING,
        ]);

        if (!$validation['valid']) {
            $failureStatus = !empty($validation['no_values_determined'])
                ? InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION
                : InventoryMasterAiSpec::STATUS_REJECTED;

            $spec->update(['status' => $failureStatus]);

            if ($failureStatus === InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION) {
                Log::info('Inventory AI enrichment marked insufficient information (no spec values determined).', [
                    'inventory_master_id' => $inventoryMasterId,
                    'spec_id' => $spec->id,
                    'errors' => $validation['errors'],
                    'confidence_score' => $mapped['confidence_score'],
                    'reasoning' => $parsed['reasoning'] ?? null,
                ]);
            } else {
                Log::info('Inventory AI enrichment rejected due to validation failure.', [
                    'inventory_master_id' => $inventoryMasterId,
                    'spec_id' => $spec->id,
                    'errors' => $validation['errors'],
                ]);
            }

            $this->rejectionService->recordForProduct(
                $product,
                $validation['errors'] !== []
                    ? 'Invalid dimensions or units: ' . implode(' ', $validation['errors'])
                    : 'AI returned insufficient data for the missing specification fields.',
                InventoryMasterAiRejection::CATEGORY_VALIDATION,
                $batchRunId,
                $spec->id,
            );

            return [
                'status' => $failureStatus,
                'spec_id' => $spec->id,
                'message' => $failureStatus === InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION
                    ? 'AI could not determine specification values for this product.'
                    : 'Validation failed: ' . implode(' ', $validation['errors']),
            ];
        }

        $confidence = (int) $mapped['confidence_score'];
        $finalStatus = $this->resolveStatusFromConfidence($confidence);

        if (
            $finalStatus === InventoryMasterAiSpec::STATUS_APPROVED
            && !InventoryMasterSpecEnrichment::allMissingFieldsCovered($missingFields, $validation['fills_missing'])
        ) {
            Log::info('Inventory AI enrichment downgraded to manual review (partial field coverage).', [
                'inventory_master_id' => $inventoryMasterId,
                'spec_id' => $spec->id,
                'confidence_score' => $confidence,
                'missing_fields' => $missingFields,
                'fills_missing' => $validation['fills_missing'],
            ]);

            $finalStatus = InventoryMasterAiSpec::STATUS_PENDING;
        }

        if ($finalStatus === InventoryMasterAiSpec::STATUS_APPROVED) {
            $this->approveAndApply($spec, $product, InventoryMasterAiLog::UPDATED_BY_AI);
            $this->rejectionService->clear($inventoryMasterId);

            return [
                'status' => InventoryMasterAiSpec::STATUS_APPROVED,
                'spec_id' => $spec->id,
                'message' => 'Auto-approved and applied to inventory_master.',
            ];
        }

        $spec->update(['status' => $finalStatus]);

        if ($finalStatus === InventoryMasterAiSpec::STATUS_PENDING) {
            $this->rejectionService->clear($inventoryMasterId);
        }

        Log::info('Inventory AI enrichment staged for manual review.', [
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
                : 'Auto-approved and applied to inventory_master.',
        ];
    }

    /**
     * Synchronously enrich a list of products (admin bulk actions).
     *
     * @param  list<int>  $inventoryMasterIds
     * @return list<array{product_id: int, product_name: string, outcome: string, status: string, message: string}>
     */
    public function enrichProductsSynchronously(
        array $inventoryMasterIds,
        bool $bypassRejectionCheck = true,
        bool $retryIncomplete = true,
    ): array {
        $results = [];
        $batchRunId = (string) str()->uuid();

        foreach ($inventoryMasterIds as $inventoryMasterId) {
            $productName = $this->rejectionService->resolveProductName($inventoryMasterId);

            try {
                $result = $this->enrichProduct(
                    $inventoryMasterId,
                    retryIncomplete: $retryIncomplete,
                    bypassRejectionCheck: $bypassRejectionCheck,
                    batchRunId: $batchRunId,
                );

                $results[] = [
                    'product_id' => $inventoryMasterId,
                    'product_name' => $productName,
                    'outcome' => $this->mapEnrichmentOutcome($result['status']),
                    'status' => $result['status'],
                    'message' => $result['message'],
                ];
            } catch (Throwable $e) {
                $failure = $this->recordProviderFailure($inventoryMasterId, $e, $batchRunId);

                $results[] = [
                    'product_id' => $inventoryMasterId,
                    'product_name' => $productName,
                    'outcome' => 'Error',
                    'status' => $failure['status'],
                    'message' => $failure['message'],
                ];
            }
        }

        return $results;
    }

    /**
     * Synchronously re-run enrichment for previously rejected products (admin action).
     *
     * @param  list<int>  $inventoryMasterIds
     * @return list<array{product_id: int, product_name: string, outcome: string, status: string, message: string}>
     */
    public function rerunRejectedProducts(array $inventoryMasterIds): array
    {
        return $this->enrichProductsSynchronously($inventoryMasterIds, true, true);
    }

    private function mapEnrichmentOutcome(string $status): string
    {
        return match ($status) {
            InventoryMasterAiSpec::STATUS_APPROVED => 'Success',
            InventoryMasterAiSpec::STATUS_PENDING => 'Success',
            InventoryMasterAiSpec::STATUS_REJECTED => 'Still Rejected',
            InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION => 'Still Rejected',
            'skipped' => 'Skipped',
            default => 'Failed',
        };
    }

    /**
     * Record a non-retryable provider failure without updating inventory_master.
     *
     * @return array{status: string, spec_id: int|null, message: string}
     */
    public function recordProviderFailure(int $inventoryMasterId, Throwable $e, ?string $batchRunId = null): array
    {
        $product = Product::query()->find($inventoryMasterId);

        if (!$product) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Product not found.',
            ];
        }

        if (InventoryMasterSpecEnrichment::hasPendingEnrichment($inventoryMasterId)) {
            return [
                'status' => 'skipped',
                'spec_id' => null,
                'message' => 'Pending enrichment record already exists.',
            ];
        }

        $provider = AiProviderFactory::activeProviderName();
        $category = $e instanceof \App\Services\InventoryAi\Exceptions\AiProviderException ? $e->category : 'unknown_error';

        $spec = $this->createStagingRecord($product, [
            'status' => InventoryMasterAiSpec::STATUS_REJECTED,
            'ai_response' => [
                'provider' => $provider,
                'provider_error' => true,
                'error_category' => $category,
                'error_message' => $e->getMessage(),
                'retryable' => $e instanceof \App\Services\InventoryAi\Exceptions\AiProviderException ? $e->isRetryable() : false,
            ],
        ]);

        Log::warning('Inventory AI provider failure recorded.', [
            'inventory_master_id' => $inventoryMasterId,
            'spec_id' => $spec->id,
            'provider' => $provider,
            'error_category' => $category,
            'error' => $e->getMessage(),
        ]);

        $this->rejectionService->recordForProduct(
            $product,
            'API failure: ' . $e->getMessage(),
            InventoryMasterAiRejection::CATEGORY_PROVIDER,
            $batchRunId,
            $spec->id,
        );

        return [
            'status' => InventoryMasterAiSpec::STATUS_REJECTED,
            'spec_id' => $spec->id,
            'message' => 'Provider failure recorded: ' . $e->getMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function approvePendingSpec(
        InventoryMasterAiSpec $spec,
        int $reviewerUserId,
        array $overrides = [],
        ?string $reviewNotes = null,
    ): void {
        if ($spec->status !== InventoryMasterAiSpec::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending records can be approved.');
        }

        $product = Product::query()->findOrFail($spec->inventory_master_id);
        $missingFields = InventoryMasterSpecEnrichment::missingSpecFields($product);
        $values = $this->mergeSpecValues($spec, $overrides);

        $validation = $this->validator->validateManualApprovalValues($values, $missingFields);
        if (!$validation['valid']) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $spec->fill([
            'height' => $this->nullableDecimal($values['height'] ?? null),
            'width' => $this->nullableDecimal($values['width'] ?? null),
            'length' => $this->nullableDecimal($values['length'] ?? null),
            'weight' => $this->nullableDecimal($values['weight'] ?? null),
            'linear_unit_id' => $values['linear_unit_id'] ?? null,
            'weight_unit_id' => $values['weight_unit_id'] ?? null,
        ]);
        $spec->save();

        $this->applyApprovedSpecToProduct(
            $spec,
            $product,
            InventoryMasterAiLog::UPDATED_BY_MANUAL,
            $reviewerUserId,
            $reviewNotes,
        );
    }

    public function rejectPendingSpec(
        InventoryMasterAiSpec $spec,
        int $reviewerUserId,
        ?string $reviewNotes = null,
    ): void {
        if ($spec->status !== InventoryMasterAiSpec::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending records can be rejected.');
        }

        DB::transaction(function () use ($spec, $reviewerUserId, $reviewNotes) {
            $spec->update([
                'status' => InventoryMasterAiSpec::STATUS_REJECTED,
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            InventoryMasterAiLog::create([
                'inventory_master_id' => $spec->inventory_master_id,
                'field_name' => (string) config('inventory_ai.rejection_log_field_name', '_rejection'),
                'old_value' => InventoryMasterAiSpec::STATUS_PENDING,
                'new_value' => $reviewNotes ?: InventoryMasterAiSpec::STATUS_REJECTED,
                'confidence_score' => $spec->confidence_score,
                'source_url' => $spec->source_url,
                'updated_by' => InventoryMasterAiLog::UPDATED_BY_MANUAL,
                'reviewed_by' => $reviewerUserId,
                'created_at' => now(),
            ]);

            $product = Product::query()->find($spec->inventory_master_id);
            if ($product) {
                $this->rejectionService->recordForProduct(
                    $product,
                    $reviewNotes ?: 'Rejected during manual AI specification review.',
                    InventoryMasterAiRejection::CATEGORY_MANUAL,
                    null,
                    $spec->id,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function updatePendingSpec(InventoryMasterAiSpec $spec, array $overrides): InventoryMasterAiSpec
    {
        if ($spec->status !== InventoryMasterAiSpec::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending records can be edited.');
        }

        $allowed = ['height', 'width', 'length', 'weight', 'linear_unit_id', 'weight_unit_id', 'source_url'];
        $payload = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $overrides)) {
                continue;
            }

            if (in_array($field, ['height', 'width', 'length', 'weight'], true)) {
                $payload[$field] = $this->nullableDecimal($overrides[$field]);
            } else {
                $payload[$field] = $overrides[$field] ?: null;
            }
        }

        $spec->update($payload);

        return $spec->fresh(['linearUnit', 'weightUnit']);
    }

    public function approveAndApply(
        InventoryMasterAiSpec $spec,
        ?Product $product = null,
        string $updatedBy = InventoryMasterAiLog::UPDATED_BY_MANUAL,
    ): void {
        $product ??= Product::query()->findOrFail($spec->inventory_master_id);

        $this->applyApprovedSpecToProduct($spec, $product, $updatedBy);
    }

    private function applyApprovedSpecToProduct(
        InventoryMasterAiSpec $spec,
        Product $product,
        string $updatedBy,
        ?int $reviewerUserId = null,
        ?string $reviewNotes = null,
    ): void {
        DB::transaction(function () use ($spec, $product, $updatedBy, $reviewerUserId, $reviewNotes) {
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
                    'reviewed_by' => $reviewerUserId,
                    'created_at' => now(),
                ]);
            }

            if ($updates === [] && $updatedBy === InventoryMasterAiLog::UPDATED_BY_MANUAL) {
                throw new RuntimeException('No inventory_master fields were updated. The product may already have all suggested values.');
            }

            if ($updates !== []) {
                $product->update($updates);
            }

            $this->rejectionService->clear($product->id);

            $specUpdate = ['status' => InventoryMasterAiSpec::STATUS_APPROVED];
            if ($reviewerUserId !== null) {
                $specUpdate['reviewed_by'] = $reviewerUserId;
                $specUpdate['reviewed_at'] = now();
                $specUpdate['review_notes'] = $reviewNotes;
            }

            $spec->update($specUpdate);
        });
    }

    private function resolveStatusFromConfidence(int $confidence): string
    {
        $autoApprove = (int) config('inventory_ai.auto_approve_threshold', 60);

        if ($confidence >= $autoApprove) {
            return InventoryMasterAiSpec::STATUS_APPROVED;
        }

        return InventoryMasterAiSpec::STATUS_PENDING;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeSpecValues(InventoryMasterAiSpec $spec, array $overrides): array
    {
        $values = [
            'height' => $spec->height,
            'width' => $spec->width,
            'length' => $spec->length,
            'weight' => $spec->weight,
            'linear_unit_id' => $spec->linear_unit_id,
            'weight_unit_id' => $spec->weight_unit_id,
        ];

        foreach ($overrides as $field => $value) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            if (in_array($field, ['height', 'width', 'length', 'weight'], true)) {
                $values[$field] = $this->nullableDecimal($value);
            } else {
                $values[$field] = $value ?: null;
            }
        }

        return $values;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
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

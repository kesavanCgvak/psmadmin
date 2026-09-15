<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PsmProductSubmission;
use App\Models\PsmProductSubmissionImage;
use App\Models\User;
use App\Notifications\PsmProductSubmitted;
use App\Support\InventoryImageManagementService;
use App\Support\InventoryImageSyncService;
use App\Support\PsmCodeGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PsmProductSubmissionService
{
    public const IMAGE_UPLOAD_DIR = 'images/psm_product_submissions';

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<UploadedFile>  $images
     */
    public function submit(User $user, array $attributes, array $images = [], ?int $primaryImageIndex = null): PsmProductSubmission
    {
        if (! $user->company_id) {
            throw ValidationException::withMessages([
                'company' => ['User does not belong to any company.'],
            ]);
        }

        $this->assertSuggestedPsmCodeAvailable($attributes['psm_code'] ?? null);

        $submission = DB::transaction(function () use ($user, $attributes, $images, $primaryImageIndex) {
            $submission = PsmProductSubmission::create([
                'submitted_by_user_id' => $user->id,
                'company_id' => $user->company_id,
                'name' => trim((string) $attributes['name']),
                'description' => $this->nullableString($attributes['description'] ?? null),
                'psm_code' => $this->nullableString($attributes['psm_code'] ?? null),
                'brand_id' => $attributes['brand_id'] ?? null,
                'category_id' => $attributes['category_id'] ?? null,
                'sub_category_id' => $attributes['sub_category_id'] ?? null,
                'webpage_url' => $this->nullableString($attributes['webpage_url'] ?? null),
                'replacement_price' => $attributes['replacement_price'] ?? null,
                'height' => $attributes['height'] ?? null,
                'width' => $attributes['width'] ?? null,
                'length' => $attributes['length'] ?? null,
                'weight' => $attributes['weight'] ?? null,
                'linear_unit_id' => $attributes['linear_unit_id'] ?? null,
                'weight_unit_id' => $attributes['weight_unit_id'] ?? null,
                'country_of_origin' => $this->nullableString($attributes['country_of_origin'] ?? null),
                'iso_code_2' => $this->nullableString($attributes['iso_code_2'] ?? null),
                'iso_code_3' => $this->nullableString($attributes['iso_code_3'] ?? null),
                'hsn_code' => $this->nullableString($attributes['hsn_code'] ?? null),
                'status' => PsmProductSubmission::STATUS_PENDING,
            ]);

            $this->storeImages($submission, $images, $primaryImageIndex);

            return $submission->fresh(['images', 'brand', 'company', 'submitter.profile']);
        });

        $this->notifyAdmin($submission);

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function approve(PsmProductSubmission $submission, int $reviewerUserId, array $overrides = []): Product
    {
        return DB::transaction(function () use ($submission, $reviewerUserId, $overrides) {
            /** @var PsmProductSubmission $locked */
            $locked = PsmProductSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                throw new InvalidArgumentException('Only pending submissions can be approved.');
            }

            $payload = $this->mergeApprovalPayload($locked, $overrides);
            $psmCode = $this->resolveApprovedPsmCode($payload['psm_code'] ?? null);

            $product = Product::create([
                'model' => $payload['name'],
                'psm_code' => $psmCode,
                'brand_id' => $payload['brand_id'],
                'category_id' => $payload['category_id'],
                'sub_category_id' => $payload['sub_category_id'],
                'webpage_url' => $payload['webpage_url'],
                'replacement_price' => $payload['replacement_price'],
                'height' => $payload['height'],
                'width' => $payload['width'],
                'length' => $payload['length'],
                'weight' => $payload['weight'],
                'linear_unit_id' => $payload['linear_unit_id'],
                'weight_unit_id' => $payload['weight_unit_id'],
                'country_of_origin' => $payload['country_of_origin'],
                'iso_code_2' => $payload['iso_code_2'],
                'iso_code_3' => $payload['iso_code_3'],
                'hsn_code' => $payload['hsn_code'],
                'is_verified' => 1,
                'source' => PsmProductSubmission::SOURCE,
            ]);

            $this->copyImagesToInventoryMaster($locked, $product, $reviewerUserId);

            $locked->update([
                'name' => $payload['name'],
                'psm_code' => $psmCode,
                'brand_id' => $payload['brand_id'],
                'category_id' => $payload['category_id'],
                'sub_category_id' => $payload['sub_category_id'],
                'webpage_url' => $payload['webpage_url'],
                'replacement_price' => $payload['replacement_price'],
                'height' => $payload['height'],
                'width' => $payload['width'],
                'length' => $payload['length'],
                'weight' => $payload['weight'],
                'linear_unit_id' => $payload['linear_unit_id'],
                'weight_unit_id' => $payload['weight_unit_id'],
                'country_of_origin' => $payload['country_of_origin'],
                'iso_code_2' => $payload['iso_code_2'],
                'iso_code_3' => $payload['iso_code_3'],
                'hsn_code' => $payload['hsn_code'],
                'status' => PsmProductSubmission::STATUS_APPROVED,
                'admin_notes' => $this->nullableString($overrides['admin_notes'] ?? $locked->admin_notes),
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => now(),
                'processed_at' => now(),
                'inventory_master_id' => $product->id,
            ]);

            $locked->delete();

            return $product->fresh();
        });
    }

    public function reject(PsmProductSubmission $submission, int $reviewerUserId, ?string $adminNotes = null): PsmProductSubmission
    {
        return DB::transaction(function () use ($submission, $reviewerUserId, $adminNotes) {
            /** @var PsmProductSubmission $locked */
            $locked = PsmProductSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                throw new InvalidArgumentException('Only pending submissions can be rejected.');
            }

            $locked->update([
                'status' => PsmProductSubmission::STATUS_REJECTED,
                'admin_notes' => $this->nullableString($adminNotes),
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatForApi(PsmProductSubmission $submission, bool $includeImages = false): array
    {
        $submission->loadMissing([
            'brand:id,name',
            'category:id,name',
            'subCategory:id,name,category_id',
            'linearUnit:id,code,name',
            'weightUnit:id,code,name',
            'company:id,name',
            'submitter.profile:id,user_id,full_name',
        ]);

        $payload = [
            'id' => $submission->id,
            'name' => $submission->name,
            'description' => $submission->description,
            'psm_code' => $submission->psm_code,
            'status' => $submission->status,
            'replacement_price' => $submission->replacement_price !== null ? (float) $submission->replacement_price : null,
            'height' => $submission->height,
            'width' => $submission->width,
            'length' => $submission->length,
            'weight' => $submission->weight,
            'linear_unit_id' => $submission->linear_unit_id,
            'weight_unit_id' => $submission->weight_unit_id,
            'country_of_origin' => $submission->country_of_origin,
            'iso_code_2' => $submission->iso_code_2,
            'iso_code_3' => $submission->iso_code_3,
            'hsn_code' => $submission->hsn_code,
            'webpage_url' => $submission->webpage_url,
            'brand' => $submission->brand ? ['id' => $submission->brand->id, 'name' => $submission->brand->name] : null,
            'category' => $submission->category ? ['id' => $submission->category->id, 'name' => $submission->category->name] : null,
            'sub_category' => $submission->subCategory ? [
                'id' => $submission->subCategory->id,
                'name' => $submission->subCategory->name,
                'category_id' => $submission->subCategory->category_id,
            ] : null,
            'admin_notes' => $submission->admin_notes,
            'inventory_master_id' => $submission->inventory_master_id,
            'created_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'processed_at' => $submission->processed_at?->toIso8601String(),
        ];

        if ($includeImages) {
            $submission->loadMissing('images');
            $payload['images'] = $submission->images->map(function (PsmProductSubmissionImage $image) {
                return [
                    'id' => $image->id,
                    'url' => InventoryImageManagementService::publicUrl((string) $image->image_path),
                    'is_primary' => (bool) $image->is_primary,
                    'sort_order' => $image->sort_order,
                ];
            })->values()->all();
        }

        return $payload;
    }

    /**
     * @param  list<UploadedFile>  $images
     */
    private function storeImages(PsmProductSubmission $submission, array $images, ?int $primaryImageIndex): void
    {
        $hasPrimary = false;
        foreach ($images as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = InventoryImageManagementService::storeUploadedFile($file, self::IMAGE_UPLOAD_DIR);
            $isPrimary = $primaryImageIndex !== null ? $index === $primaryImageIndex : $index === 0;

            if ($isPrimary && $hasPrimary) {
                $isPrimary = false;
            }

            PsmProductSubmissionImage::create([
                'psm_product_submission_id' => $submission->id,
                'image_path' => $path,
                'is_primary' => $isPrimary,
                'sort_order' => $index + 1,
            ]);

            if ($isPrimary) {
                $hasPrimary = true;
            }
        }

        if (! $hasPrimary) {
            $first = PsmProductSubmissionImage::query()
                ->where('psm_product_submission_id', $submission->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
            if ($first) {
                $first->update(['is_primary' => true]);
            }
        }
    }

    private function copyImagesToInventoryMaster(PsmProductSubmission $submission, Product $product, int $reviewerUserId): void
    {
        $images = $submission->images()->orderBy('sort_order')->orderBy('id')->get();
        $primary = null;

        foreach ($images as $image) {
            $masterPath = $this->copyImageToMasterDirectory((string) $image->image_path);
            $masterImage = InventoryImageManagementService::addMasterImage(
                (int) $product->id,
                null,
                $masterPath,
                $reviewerUserId
            );
            $masterImage->update(['source' => PsmProductSubmission::SOURCE]);

            if ($image->is_primary) {
                $primary = $masterImage;
            }
        }

        if ($primary) {
            InventoryImageManagementService::setMasterPrimary((int) $product->id, $primary);
        } else {
            InventoryImageSyncService::ensureMasterPrimary((int) $product->id);
        }
    }

    private function copyImageToMasterDirectory(string $sourcePath): string
    {
        if (str_starts_with($sourcePath, 'http')) {
            return $sourcePath;
        }

        $absoluteSource = public_path($sourcePath);
        if (! is_file($absoluteSource)) {
            return $sourcePath;
        }

        $dir = InventoryImageManagementService::MASTER_UPLOAD_DIR;
        $destinationDir = public_path($dir);
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $filename = time().'_'.uniqid().'_'.basename($sourcePath);
        $relative = rtrim($dir, '/').'/'.$filename;
        copy($absoluteSource, public_path($relative));

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeApprovalPayload(PsmProductSubmission $submission, array $overrides): array
    {
        $fields = [
            'name', 'psm_code', 'brand_id', 'category_id', 'sub_category_id', 'webpage_url',
            'replacement_price', 'height', 'width', 'length', 'weight',
            'linear_unit_id', 'weight_unit_id', 'country_of_origin', 'iso_code_2', 'iso_code_3', 'hsn_code',
        ];

        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = array_key_exists($field, $overrides)
                ? ($overrides[$field] === '' ? null : $overrides[$field])
                : $submission->{$field};
        }

        $payload['name'] = trim((string) $payload['name']);
        if ($payload['name'] === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        return $payload;
    }

    private function resolveApprovedPsmCode(?string $suggested): string
    {
        $suggested = $this->nullableString($suggested);
        if ($suggested !== null) {
            if (! PsmCodeGenerator::isAvailable($suggested)) {
                throw new InvalidArgumentException('PSM code already exists in inventory.');
            }

            return $suggested;
        }

        return PsmCodeGenerator::next();
    }

    private function assertSuggestedPsmCodeAvailable(?string $psmCode): void
    {
        $code = $this->nullableString($psmCode);
        if ($code === null) {
            return;
        }

        if (! PsmCodeGenerator::isAvailable($code)) {
            throw ValidationException::withMessages([
                'psm_code' => ['This PSM code already exists in inventory.'],
            ]);
        }

        $pendingExists = PsmProductSubmission::query()
            ->where('status', PsmProductSubmission::STATUS_PENDING)
            ->where('psm_code', $code)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'psm_code' => ['This PSM code is already used on a pending submission.'],
            ]);
        }
    }

    private function notifyAdmin(PsmProductSubmission $submission): void
    {
        $adminEmail = config('mail.admin.address');

        if (! is_string($adminEmail) || trim($adminEmail) === '') {
            Log::warning('PsmProductSubmissionService: admin notification skipped, no recipient configured', [
                'submission_id' => $submission->id,
                'config_key' => 'mail.admin.address',
                'env_key' => 'MAIL_TO_ADMIN',
            ]);

            return;
        }

        $adminEmail = trim($adminEmail);

        try {
            $submission->loadMissing(['submitter.profile', 'company']);

            Log::info('PsmProductSubmissionService: sending admin notification', [
                'submission_id' => $submission->id,
                'to' => $adminEmail,
                'from' => config('mail.from.address'),
                'subject' => 'New PSM Product Submitted for Review',
            ]);

            Notification::route('mail', $adminEmail)
                ->notify(new PsmProductSubmitted($submission));

            Log::info('PsmProductSubmissionService: admin notification sent', [
                'submission_id' => $submission->id,
                'to' => $adminEmail,
            ]);
        } catch (\Throwable $e) {
            Log::error('PsmProductSubmissionService: admin notification failed', [
                'submission_id' => $submission->id,
                'to' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

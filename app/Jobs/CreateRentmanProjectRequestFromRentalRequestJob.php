<?php

namespace App\Jobs;

use App\Models\Equipment;
use App\Models\RentalJob;
use App\Models\RentalRequestProviderQuote;
use App\Models\RentmanIntegrationLog;
use App\Models\RentmanProjectRequestSyncLog;
use App\Models\SupplyJob;
use App\Services\FlexIntegrationService;
use App\Services\RentmanIntegrationLogger;
use App\Services\RentmanIntegrationService;
use App\Support\RentmanIntegrationDebugLog;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Runs synchronously when dispatched (does not implement ShouldQueue).
 * Rentman Project Request creation is not queued — mirrors Flex quote job.
 */
class CreateRentmanProjectRequestFromRentalRequestJob
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $rentalJobId) {}

    public function handle(): void
    {
        $rentalJob = RentalJob::query()
            ->with([
                'user.profile',
                'user.company.country',
                'user.company.state',
                'user.company.city',
                'supplyJobs.provider',
                'supplyJobs.products.product.brand',
            ])
            ->find($this->rentalJobId);

        if (!$rentalJob || !$rentalJob->user) {
            RentmanIntegrationDebugLog::warning($this->rentalJobId, null, 'RENTMAN_PROJECT_REQUEST_JOB', 'ABORTED', [
                'reason' => 'rental_job_or_requester_missing',
            ]);

            return;
        }

        RentmanIntegrationDebugLog::step(
            $rentalJob->id,
            null,
            'RENTMAN_PROJECT_REQUEST_JOB',
            'STARTED',
            [
                'supply_jobs_count' => $rentalJob->supplyJobs->count(),
                'execution' => 'synchronous',
            ],
            'Process each supply job: check Rentman integration, create contact/request, attach equipment',
        );

        foreach ($rentalJob->supplyJobs as $supplyJob) {
            $this->processSupplyJob($rentalJob, $supplyJob);
        }

        RentmanIntegrationDebugLog::step(
            $rentalJob->id,
            null,
            'RENTMAN_PROJECT_REQUEST_JOB',
            'SUMMARY',
            [],
            'Roll up rentman_sync_status onto rental_jobs',
        );

        $this->refreshRentalJobRentmanSummary($rentalJob->id);

        RentmanIntegrationDebugLog::step(
            $rentalJob->id,
            null,
            'RENTMAN_PROJECT_REQUEST_JOB',
            'ALL_PROVIDERS_DONE',
            [],
            'Done — inspect storage/logs/rentman-integration.log and rentman_integration_logs table',
        );
    }

    protected function processSupplyJob(RentalJob $rentalJob, SupplyJob $supplyJob): void
    {
        $steps = [];
        $appendStep = function (string $message) use (&$steps) {
            $steps[] = $message;
        };

        $providerId = (int) $supplyJob->provider_id;
        $ilog = new RentmanIntegrationLogger($rentalJob->id, $providerId);

        RentmanIntegrationDebugLog::resetStepCounter($rentalJob->id, $providerId);

        RentmanIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'PROVIDER_PROCESSING',
            'STARTED',
            ['supply_job_id' => $supplyJob->id],
            'Check whether provider uses Rentman rental software and has company_integrations row',
        );

        $appendStep('Rentman integration started for provider supply job');

        $ilog->log(
            RentmanIntegrationLog::ACTION_CHECK_INTEGRATION,
            RentmanIntegrationLog::STATUS_PROCESSING,
            null,
            ['supply_job_id' => $supplyJob->id],
            null,
        );

        $syncLog = RentmanProjectRequestSyncLog::query()->create([
            'rental_job_id' => $rentalJob->id,
            'supply_job_id' => $supplyJob->id,
            'provider_company_id' => $providerId,
            'status' => RentmanIntegrationService::SYNC_PENDING,
            'steps' => $steps,
        ]);

        if (!RentmanIntegrationService::checkCompanyIntegration($providerId)) {
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CHECK_INTEGRATION',
                'SKIPPED',
                ['reason' => 'not_rentman_provider_or_no_integration_row'],
                'Skip Rentman for this provider; process next supply job',
            );
            $appendStep('Skipped: provider has no Rentman integration');
            $ilog->log(
                RentmanIntegrationLog::ACTION_CHECK_INTEGRATION,
                RentmanIntegrationLog::STATUS_SKIPPED,
                null,
                ['reason' => 'rental_software_or_integration'],
                null,
            );
            $syncLog->update(['status' => RentmanIntegrationService::SYNC_COMPLETED, 'steps' => $steps]);

            return;
        }

        RentmanIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'CHECK_INTEGRATION',
            'SUCCESS',
            ['supply_job_id' => $supplyJob->id],
            'Load Rentman credentials from company_integrations and run pre-flight diagnostics',
        );

        $ilog->log(
            RentmanIntegrationLog::ACTION_CHECK_INTEGRATION,
            RentmanIntegrationLog::STATUS_SUCCESS,
            null,
            ['supply_job_id' => $supplyJob->id],
            null,
        );

        if ($supplyJob->rentman_project_request_id) {
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CREATE_PROJECT_REQUEST',
                'SKIPPED',
                [
                    'reason' => 'rentman_project_request_id_already_set',
                    'existing_rentman_project_request_id' => $supplyJob->rentman_project_request_id,
                ],
                'Skip project request creation; process next supply job',
            );
            $appendStep('Skipped: rentman_project_request_id already set (duplicate prevention)');
            $ilog->log(
                RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
                RentmanIntegrationLog::STATUS_SKIPPED,
                null,
                ['existing_rentman_project_request_id' => $supplyJob->rentman_project_request_id],
                null,
                null,
                $supplyJob->rentman_project_request_id,
            );
            $syncLog->update([
                'status' => RentmanIntegrationService::SYNC_COMPLETED,
                'rentman_project_request_id' => $supplyJob->rentman_project_request_id,
                'rentman_project_request_displayname' => $supplyJob->rentman_project_request_displayname,
                'steps' => $steps,
            ]);

            return;
        }

        $service = RentmanIntegrationService::forProviderCompany($providerId);
        if (!$service) {
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CHECK_INTEGRATION',
                'SKIPPED',
                ['reason' => 'incomplete_rentman_api_credentials'],
                'Skip Rentman for this provider; process next supply job',
            );
            $appendStep('Skipped: Rentman credentials incomplete');
            $ilog->log(
                RentmanIntegrationLog::ACTION_CHECK_INTEGRATION,
                RentmanIntegrationLog::STATUS_SKIPPED,
                null,
                ['reason' => 'incomplete_credentials'],
                null,
            );
            $syncLog->update(['status' => RentmanIntegrationService::SYNC_COMPLETED, 'steps' => $steps]);

            return;
        }

        $service->setRentmanLogger($ilog);
        $service->setRentalRequestId($rentalJob->id);
        $service->logPreFlightDiagnostics($rentalJob->id);

        $supplyJob->rentman_sync_status = RentmanIntegrationService::SYNC_PROCESSING;
        $supplyJob->save();

        $attached = [];
        $missing = [];
        $missingForEmail = [];
        $rentmanContactId = null;
        $projectRequestId = null;
        $projectRequestDisplayname = null;

        try {
            // --- Step 1: Resolve / create contact BEFORE project request ---
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CREATE_CONTACT',
                'STARTED',
                [],
                'Retrieve all Rentman contacts, match requester, create if missing',
            );

            $rentmanContactId = $service->getOrCreateContact($rentalJob->user);
            $appendStep('Contact resolved in Rentman');

            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CREATE_CONTACT',
                'SUCCESS',
                ['rentman_contact_id' => $rentmanContactId],
                'Resolve all equipment before creating the project request',
            );

            // --- Step 2: Resolve ALL equipment BEFORE project request ---
            /** @var list<array{product_id: int, name: string, quantity: int, rentman_equipment_id: ?string, unit_price: ?float, inventory: ?Equipment}> $resolvedLines */
            $resolvedLines = [];

            foreach ($supplyJob->products as $line) {
                $product = $line->product;
                if (!$product) {
                    continue;
                }

                $qty = (int) ($line->required_quantity ?? $line->offered_quantity ?? 0);
                if ($qty < 1) {
                    continue;
                }

                $displayName = FlexIntegrationService::productDisplayName($product);

                RentmanIntegrationDebugLog::step(
                    $rentalJob->id,
                    $providerId,
                    'PRODUCT_LINE',
                    'STARTED',
                    [
                        'product_id' => $product->id,
                        'name' => $displayName,
                        'quantity' => $qty,
                    ],
                    'Resolve rentman_equipment_id from cache or search (before project request)',
                );

                $inventory = Equipment::query()
                    ->where('company_id', $providerId)
                    ->where('product_id', $product->id)
                    ->first();

                $cachedId = $inventory?->rentman_equipment_id;

                $rentmanEquipmentId = $service->resolveRentmanEquipmentForProduct(
                    $displayName,
                    $cachedId !== null && $cachedId !== '' ? (string) $cachedId : null,
                    (int) $product->id,
                    $inventory,
                );

                if (!$rentmanEquipmentId) {
                    $ilog->log(
                        RentmanIntegrationLog::ACTION_PRODUCT_NOT_FOUND,
                        RentmanIntegrationLog::STATUS_SKIPPED,
                        null,
                        ['product_id' => $product->id, 'name' => $displayName, 'quantity' => $qty],
                        null,
                        'Not found in Rentman equipment catalog; will add to project request by name',
                    );
                    RentmanIntegrationDebugLog::step(
                        $rentalJob->id,
                        $providerId,
                        'PRODUCT_NOT_FOUND',
                        'SKIPPED',
                        [
                            'product_id' => $product->id,
                            'name' => $displayName,
                            'quantity' => $qty,
                        ],
                        'Create project request, then POST projectrequestequipment with name only',
                    );
                    $appendStep('Product not found in Rentman; will add by name: ' . $displayName);

                    $resolvedLines[] = [
                        'product_id' => (int) $product->id,
                        'name' => $displayName,
                        'quantity' => $qty,
                        'rentman_equipment_id' => null,
                        'unit_price' => $inventory?->rental_price !== null ? (float) $inventory->rental_price : null,
                        'inventory' => $inventory,
                    ];

                    continue;
                }

                RentmanIntegrationDebugLog::step(
                    $rentalJob->id,
                    $providerId,
                    'PRODUCT_RESOLVED',
                    'READY',
                    [
                        'product_id' => $product->id,
                        'rentman_equipment_id' => $rentmanEquipmentId,
                        'name' => $displayName,
                    ],
                    'Continue resolving remaining equipment, then create project request',
                );

                $resolvedLines[] = [
                    'product_id' => (int) $product->id,
                    'name' => $displayName,
                    'quantity' => $qty,
                    'rentman_equipment_id' => $rentmanEquipmentId,
                    'unit_price' => $inventory?->rental_price !== null ? (float) $inventory->rental_price : null,
                    'inventory' => $inventory,
                ];
                $appendStep('Equipment resolved: ' . $displayName);
            }

            // Create a Project Request when there is at least one product line to attach
            if ($resolvedLines === []) {
                throw new \RuntimeException(
                    'Rentman project request not created: no resolvable product lines'
                );
            }

            // --- Step 3: Create Project Request (with linked_contact, location, remark, periods) ---
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CREATE_PROJECT_REQUEST',
                'STARTED',
                [
                    'rentman_contact_id' => $rentmanContactId,
                    'resolved_equipment_count' => count($resolvedLines),
                ],
                'POST /projectrequests with full contact/location/rental payload',
            );

            $projectRequest = $service->createProjectRequest(
                $rentalJob,
                $rentmanContactId,
                $supplyJob,
                $rentalJob->user,
            );
            $projectRequestId = $projectRequest['id'];
            $projectRequestDisplayname = $projectRequest['displayname'];
            $appendStep('Project request created in Rentman');

            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CREATE_PROJECT_REQUEST',
                'SUCCESS',
                [
                    'rentman_project_request_id' => $projectRequestId,
                    'displayname' => $projectRequestDisplayname,
                ],
                'Attach previously resolved equipment lines',
            );

            // --- Step 4: Attach previously resolved equipment ---
            $externalRemark = $supplyJob->is_similar_request ? 'Similar products acceptable' : null;

            foreach ($resolvedLines as $resolved) {
                try {
                    RentmanIntegrationDebugLog::step(
                        $rentalJob->id,
                        $providerId,
                        'ADD_EQUIPMENT_TO_REQUEST',
                        'STARTED',
                        [
                            'product_id' => $resolved['product_id'],
                            'rentman_equipment_id' => $resolved['rentman_equipment_id'],
                            'quantity' => $resolved['quantity'],
                            'rentman_project_request_id' => $projectRequestId,
                        ],
                        'POST projectrequestequipment',
                    );

                    $equipmentId = $resolved['rentman_equipment_id'];
                    $attachResult = $equipmentId
                        ? $service->attachEquipmentToProjectRequest(
                            $projectRequestId,
                            $equipmentId,
                            $resolved['name'],
                            $resolved['quantity'],
                            $resolved['unit_price'],
                            $externalRemark,
                        )
                        : $service->attachUnlinkedProductNameToProjectRequest(
                            $projectRequestId,
                            $resolved['name'],
                        );

                    RentmanIntegrationDebugLog::step(
                        $rentalJob->id,
                        $providerId,
                        'ADD_EQUIPMENT_TO_REQUEST',
                        'SUCCESS',
                        [
                            'product_id' => $resolved['product_id'],
                            'rentman_line_id' => $attachResult['rentman_line_id'],
                        ],
                        'Process next product line or finalize sync status',
                    );

                    $attached[] = [
                        'product_id' => $resolved['product_id'],
                        'name' => $resolved['name'],
                        'quantity' => $resolved['quantity'],
                        'rentman_equipment_id' => $resolved['rentman_equipment_id'],
                        'rentman_line_id' => $attachResult['rentman_line_id'],
                    ];
                } catch (\Throwable $e) {
                    RentmanIntegrationDebugLog::step(
                        $rentalJob->id,
                        $providerId,
                        'ADD_EQUIPMENT_TO_REQUEST',
                        'FAILED',
                        [
                            'product_id' => $resolved['product_id'],
                            'error' => $e->getMessage(),
                        ],
                        'Continue with next product line; mark this product as missing',
                    );
                    $missing[] = $resolved['name'] . ' (attach failed)';
                    $missingForEmail[] = [
                        'name' => $resolved['name'] . ' (attach failed)',
                        'quantity' => $resolved['quantity'],
                    ];
                    $appendStep('Rentman error attaching product: ' . $e->getMessage());
                }
            }

            // --- Step 5: Ensure marketplace notes are on the request (remark already on create; soft-fail update) ---
            if ($service->addProjectRequestNoteFromRentalMessages($projectRequestId, $rentalJob, $supplyJob)) {
                $appendStep('Project request note confirmed from rental request messages');
            }

            if ($missingForEmail !== []) {
                $supplyJob->provider?->loadMissing('getDefaultcontact');
                if ($supplyJob->provider) {
                    RentmanIntegrationDebugLog::step(
                        $rentalJob->id,
                        $providerId,
                        'MISSING_PRODUCTS_EMAIL',
                        'STARTED',
                        ['missing_count' => count($missingForEmail)],
                        'Email provider about products not attached in Rentman',
                    );
                    $service->sendMissingProductsEmail($supplyJob->provider, $rentalJob, $missingForEmail);
                }
            }

            if ($missing === []) {
                $syncStatus = RentmanIntegrationService::SYNC_COMPLETED;
            } elseif ($attached === []) {
                $syncStatus = RentmanIntegrationService::SYNC_FAILED;
            } else {
                $syncStatus = RentmanIntegrationService::SYNC_PARTIAL;
            }

            $supplyJob->rentman_project_request_id = $projectRequestId;
            $supplyJob->rentman_project_request_displayname = $projectRequestDisplayname;
            $supplyJob->rentman_sync_status = $syncStatus;
            $supplyJob->save();

            RentalRequestProviderQuote::query()->updateOrCreate(
                [
                    'rental_request_id' => $rentalJob->id,
                    'provider_id' => $providerId,
                ],
                [
                    'supply_job_id' => $supplyJob->id,
                    'rentman_project_request_id' => $projectRequestId,
                    'rentman_project_request_displayname' => $projectRequestDisplayname,
                    'status' => $syncStatus,
                ],
            );

            $syncLog->update([
                'status' => $syncStatus,
                'rentman_contact_id' => $rentmanContactId,
                'rentman_project_request_id' => $projectRequestId,
                'rentman_project_request_displayname' => $projectRequestDisplayname,
                'products_attached' => $attached,
                'products_missing' => $missing,
                'steps' => $steps,
            ]);

            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'PROVIDER_PROCESSING',
                'FINISHED',
                [
                    'sync_status' => $syncStatus,
                    'rentman_project_request_id' => $projectRequestId,
                    'attached_count' => count($attached),
                    'missing_count' => count($missing),
                    'rental_request_id' => $rentalJob->id,
                    'provider_id' => $providerId,
                ],
                'Process next supply job or refresh rental job Rentman summary',
            );

            \Illuminate\Support\Facades\Log::info('Rentman Integration: Provider sync final success', [
                'rental_request_id' => $rentalJob->id,
                'provider_id' => $providerId,
                'rentman_company_base_url' => $service->getBaseUrlForLogging(),
                'sync_status' => $syncStatus,
                'rentman_project_request_id' => $projectRequestId,
                'displayname' => $projectRequestDisplayname,
                'attached_count' => count($attached),
                'missing_count' => count($missing),
            ]);
        } catch (\Throwable $e) {
            RentmanIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'RENTMAN_PROJECT_REQUEST_FLOW',
                'FAILED',
                [
                    'supply_job_id' => $supplyJob->id,
                    'error' => $e->getMessage(),
                ],
                'Mark supply job FAILED and continue with next provider',
            );

            $appendStep('Rentman error: ' . $e->getMessage());

            $ilog->log(
                RentmanIntegrationLog::ACTION_API_ERROR,
                RentmanIntegrationLog::STATUS_FAILED,
                null,
                null,
                null,
                $e->getMessage(),
                $projectRequestId,
            );

            $supplyJob->rentman_sync_status = RentmanIntegrationService::SYNC_FAILED;
            $supplyJob->save();

            RentalRequestProviderQuote::query()->updateOrCreate(
                [
                    'rental_request_id' => $rentalJob->id,
                    'provider_id' => $providerId,
                ],
                [
                    'supply_job_id' => $supplyJob->id,
                    'rentman_project_request_id' => $projectRequestId,
                    'rentman_project_request_displayname' => $projectRequestDisplayname,
                    'status' => RentmanIntegrationService::SYNC_FAILED,
                ],
            );

            $syncLog->update([
                'status' => RentmanIntegrationService::SYNC_FAILED,
                'rentman_contact_id' => $rentmanContactId,
                'rentman_project_request_id' => $projectRequestId,
                'rentman_project_request_displayname' => $projectRequestDisplayname,
                'products_attached' => $attached,
                'products_missing' => $missing,
                'error_message' => $e->getMessage(),
                'steps' => $steps,
            ]);
        }
    }

    protected function refreshRentalJobRentmanSummary(int $rentalJobId): void
    {
        $supplyJobs = SupplyJob::query()
            ->where('rental_job_id', $rentalJobId)
            ->get();

        $withStatus = $supplyJobs->filter(
            fn (SupplyJob $sj) => $sj->rentman_sync_status !== null && $sj->rentman_sync_status !== ''
        );
        if ($withStatus->isEmpty()) {
            return;
        }

        $values = $withStatus->pluck('rentman_sync_status')->unique()->values()->all();

        $overall = RentmanIntegrationService::SYNC_COMPLETED;
        if (in_array(RentmanIntegrationService::SYNC_FAILED, $values, true)) {
            if (
                in_array(RentmanIntegrationService::SYNC_COMPLETED, $values, true)
                || in_array(RentmanIntegrationService::SYNC_PARTIAL, $values, true)
            ) {
                $overall = RentmanIntegrationService::SYNC_PARTIAL;
            } else {
                $overall = RentmanIntegrationService::SYNC_FAILED;
            }
        } elseif (in_array(RentmanIntegrationService::SYNC_PARTIAL, $values, true)) {
            $overall = RentmanIntegrationService::SYNC_PARTIAL;
        }

        $quoted = $supplyJobs->filter(fn (SupplyJob $sj) => !empty($sj->rentman_project_request_id));
        $rentalJob = RentalJob::query()->find($rentalJobId);
        if (!$rentalJob) {
            return;
        }

        if ($quoted->count() === 1) {
            $first = $quoted->first();
            $rentalJob->rentman_project_request_id = $first->rentman_project_request_id;
            $rentalJob->rentman_project_request_displayname = $first->rentman_project_request_displayname;
        } else {
            $rentalJob->rentman_project_request_id = null;
            $rentalJob->rentman_project_request_displayname = null;
        }

        $rentalJob->rentman_sync_status = $overall;
        $rentalJob->save();
    }
}

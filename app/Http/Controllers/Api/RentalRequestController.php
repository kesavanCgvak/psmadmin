<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Models\Product;
use App\Models\Company;
use App\Models\SupplyJobProduct;
use App\Models\RentalJobProduct;
use App\Models\RentalJobComment;
use App\Models\JobOffer;
use App\Models\Equipment;
use App\Services\SupplierSmsNotifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class RentalRequestController extends Controller
{
    public function store(Request $request)
    {
        //Authenticate the user via JWT
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            Log::warning('Unauthorized rental request attempt', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        //Validate incoming payload
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after:from_date',
            'delivery_address' => 'required|string|max:255',
            'offer_requirements' => 'nullable|string',
            'global_message' => 'nullable|string',
            'company_products' => 'required|array|min:1',
            'company_products.*.company_id' => 'required|integer|exists:companies,id',
            'company_products.*.products' => 'required|array|min:1',
            'company_products.*.products.*.product_id' => 'required|integer|exists:products,id',
            'company_products.*.products.*.requested_quantity' => 'required|integer|min:1',
            // Per-product similar flag (preferred)
            'company_products.*.products.*.is_similar' => 'nullable|boolean',
            'company_products.*.private_message' => 'nullable|string',
            'company_products.*.initial_offer' => 'nullable|numeric|min:0',
            // Deprecated: company-level similar flag (kept for backward compatibility)
            'company_products.*.is_similar' => 'nullable|boolean'
        ]);

        try {
            $user->load('company');

            return DB::transaction(function () use ($validated, $user) {

                // Create rental job
                $rentalJob = RentalJob::create([
                    'user_id' => $user->id,
                    'name' => $validated['name'],
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date'],
                    'delivery_address' => $validated['delivery_address'],
                    'offer_requirements' => $validated['offer_requirements'] ?? null,
                    'global_message' => $validated['global_message'] ?? null,
                    'status' => 'open'
                ]);

                /**
                 * Aggregate products across all companies
                 */
                $productTotals = collect($validated['company_products'])
                    ->flatMap(fn($cp) => $cp['products'])
                    ->groupBy('product_id')
                    ->map(fn($items) => $items->sum('requested_quantity'));

                foreach ($productTotals as $productId => $totalQuantity) {
                    RentalJobProduct::create([
                        'rental_job_id' => $rentalJob->id,
                        'product_id' => $productId,
                        'requested_quantity' => $totalQuantity,
                        'fulfilled_quantity' => 0,
                        'status' => 'pending',
                        'company_id' => null
                    ]);
                }

                /**
                 * Pre-load all companies, products and equipment in bulk (avoids N+1)
                 */
                $companyIds = collect($validated['company_products'])->pluck('company_id')->unique()->values()->all();
                $productIds = collect($validated['company_products'])
                    ->flatMap(fn($cp) => collect($cp['products'])->pluck('product_id'))
                    ->unique()->values()->all();

                $companies = Company::with(['getDefaultcontact', 'currency'])
                    ->whereIn('id', $companyIds)
                    ->get()
                    ->keyBy('id');

                $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

                $equipmentRows = Equipment::whereIn('company_id', $companyIds)
                    ->whereIn('product_id', $productIds)
                    ->get();
                $equipmentByCompanyProduct = $equipmentRows->groupBy('company_id')->map->keyBy('product_id');

                /**
                 * Per company supply job creation
                 */
                foreach ($validated['company_products'] as $companyData) {
                    $companyId = $companyData['company_id'];
                    $company = $companies->get($companyId);
                    $receiverCurrencyId = $company->currency_id ?? null;
                    $currencySymbol = $company->relationLoaded('currency') && $company->currency
                        ? $company->currency->symbol
                        : '';

                    // Determine whether this company is open to similar products
                    // Prefer per-product flags, fall back to legacy company-level flag.
                    $productsPayload = $companyData['products'] ?? [];
                    $hasProductLevelSimilar = collect($productsPayload)->contains(function ($product) {
                        return !empty($product['is_similar']);
                    });
                    $isSimilarRequest = $hasProductLevelSimilar || (bool) ($companyData['is_similar'] ?? false);

                    $supplyJob = SupplyJob::create([
                        'rental_job_id' => $rentalJob->id,
                        'provider_id' => $companyId,
                        'status' => 'pending',
                        'is_similar_request' => $isSimilarRequest,
                    ]);

                    $productsForMail = [];
                    $companyEquipment = $equipmentByCompanyProduct->get($companyId, collect());

                    foreach ($companyData['products'] as $product) {
                        $productId = $product['product_id'];
                        $equipment = $companyEquipment->get($productId);
                        $pricePerUnit = $equipment?->price;

                        SupplyJobProduct::create([
                            'supply_job_id' => $supplyJob->id,
                            'product_id' => $productId,
                            'required_quantity' => $product['requested_quantity'],
                            'offered_quantity' => $product['requested_quantity'],
                            'price_per_unit' => $pricePerUnit,
                            'is_similar' => (bool) ($product['is_similar'] ?? false),
                        ]);

                        $productModel = $products->get($productId);
                        if ($productModel) {
                            $productsForMail[] = [
                                'model' => $productModel->model,
                                'requested_quantity' => $product['requested_quantity'],
                                'is_similar' => (bool) ($product['is_similar'] ?? false),
                                'psm_code' => $productModel->psm_code,
                                'software_code' => $equipment?->software_code,
                                'price_per_unit' => $pricePerUnit,
                                'total_price' => $pricePerUnit ? $pricePerUnit * $product['requested_quantity'] : 0,
                            ];
                        }
                    }

                    $totalQuotePrice = collect($productsForMail)->sum('total_price');
                    $supplyJob->update(['quote_price' => $totalQuotePrice]);

                    if (!empty($companyData['private_message'])) {
                        RentalJobComment::create([
                            'rental_job_id' => $rentalJob->id,
                            'supply_job_id' => $supplyJob->id,
                            'sender_id' => $user->id,
                            'message' => $companyData['private_message'],
                            'is_private' => true
                        ]);
                    }

                    if (array_key_exists('initial_offer', $companyData) || $totalQuotePrice > 0) {
                        $totalPrice = $companyData['initial_offer'] ?? null;
                        $totalPrice = ($totalPrice && $totalPrice > 0) ? $totalPrice : $totalQuotePrice;
                        JobOffer::create([
                            'rental_job_id' => $rentalJob->id,
                            'supply_job_id' => $supplyJob->id,
                            'sender_company_id' => $user->company_id,
                            'receiver_company_id' => $companyId,
                            'version' => 1,
                            'total_price' => $totalPrice,
                            'currency_id' => $receiverCurrencyId,
                            'last_offer_by' => $user->company_id,
                            'status' => 'pending'
                        ]);
                    }

                    if ($company && $company->getDefaultcontact) {
                            $defaultContact = $company->getDefaultcontact;
                            $providerContactName = $defaultContact->full_name ?? $defaultContact->email ?? 'there';

                            // Pre-render sections for DB template compatibility (no @if/@foreach in stored body)
                            $globalMessageSection = '';
                            if (!empty($validated['global_message'])) {
                                $globalMessageSection = '<h3 style="color: #1a73e8;">Global Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">' . e($validated['global_message']) . '</p>';
                            }
                            $offerRequirementsSection = '';
                            if (!empty($validated['offer_requirements'])) {
                                $offerRequirementsSection = '<h3 style="color: #1a73e8;">Offer Requirements</h3><p>' . e($validated['offer_requirements']) . '</p>';
                            }
                            $privateMessageSection = '';
                            if (!empty($companyData['private_message'])) {
                                $privateMessageSection = '<h3 style="color: #1a73e8;">Private Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">' . e($companyData['private_message']) . '</p>';
                            }
                            $initialOfferSection = '';
                            if (isset($companyData['initial_offer']) && $companyData['initial_offer'] !== null && $companyData['initial_offer'] !== '') {
                                $initialOfferSection = '<h3 style="color: #1a73e8;">Initial Offer Negotiation</h3><p><b>Offer Price : </b>' . $currencySymbol . number_format((float) $companyData['initial_offer'], 2) . '</p>';
                            }
                            $similarRequestNote = '';
                            if ($isSimilarRequest) {
                                $similarRequestNote = '<p style="margin-top: 15px; padding: 12px; background-color: #e8f4fd; border-left: 4px solid #1a73e8; font-size: 14px; line-height: 1.5;"><strong>Note:</strong> The requester is also open to similar or equivalent products. Please contact the requester if you can offer suitable alternatives.</p>';
                            }

                            $grandTotal = 0;
                            $productsTableRows = '';
                            foreach ($productsForMail as $p) {
                                $itemTotal = $p['total_price'] ?? 0;
                                $grandTotal += $itemTotal;
                                $model = e($p['model'] ?? '-');
                                $psmCode = e($p['psm_code'] ?? '—');
                                $softwareCode = e($p['software_code'] ?? '—');
                                $qty = (int) ($p['requested_quantity'] ?? 0);
                                $similarOk = !empty($p['is_similar']) ? 'Yes' : 'No';
                                $pricePerUnit = $currencySymbol . number_format((float) ($p['price_per_unit'] ?? 0), 2);
                                $totalFormatted = $currencySymbol . number_format((float) $itemTotal, 2);
                                $productsTableRows .= "<tr style=\"border-bottom: 1px solid #eee;\"><td>{$model}</td><td>{$psmCode}</td><td>{$softwareCode}</td><td>{$qty}</td><td>{$similarOk}</td><td>{$pricePerUnit}</td><td>{$totalFormatted}</td></tr>";
                            }
                            $grandTotalFormatted = $currencySymbol . number_format((float) $grandTotal, 2);
                            $productsTableHtml = '<h3 style="color: #1a73e8;">Requested Equipment</h3><table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; margin-top: 10px; font-size: 14px;"><thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;"><tr><th align="left">Equipment</th><th align="left">PSM Code</th><th align="left">Software Code</th><th align="left">Qty</th><th align="left">Similar OK?</th><th align="left">Price</th><th align="left">Total Price</th></tr></thead><tbody>' . $productsTableRows . '<tr style="border-top: 2px solid #ddd; background-color: #f9f9f9;"><td colspan="6" align="right" style="font-weight: bold; padding-right: 10px;">Grand Total:</td><td style="font-weight: bold;">' . $grandTotalFormatted . '</td></tr></tbody></table>';

                            $mailContent = [
                                'rental_name' => $validated['name'],
                                'from_date' => $validated['from_date'],
                                'to_date' => $validated['to_date'],
                                'delivery_address' => $validated['delivery_address'] ?? '',
                                'provider_contact_name' => $providerContactName,
                                'user_name' => $user->profile->full_name ?? $user->name ?? 'Unknown User',
                                'user_email' => $user->profile->email ?? '',
                                'user_mobile' => $user->profile->mobile ?? '',
                                'user_company' => $user->company->name ?? 'N/A',
                                'currency_symbol' => $currencySymbol,
                                'global_message_section' => $globalMessageSection,
                                'offer_requirements_section' => $offerRequirementsSection,
                                'private_message_section' => $privateMessageSection,
                                'initial_offer_section' => $initialOfferSection,
                                'products_table_html' => $productsTableHtml,
                                'similar_request_note' => $similarRequestNote,
                            ];

                        \App\Helpers\EmailHelper::send('quoteRequest', $mailContent, function ($message) use ($company, $validated) {
                            $message->to($company->getDefaultcontact->email, $validated['name'])
                                ->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }

                    SupplierSmsNotifier::notifyIfNeeded($supplyJob, $rentalJob, $user);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Rental job created successfully',
                    'data' => [
                        'rental_job_id' => $rentalJob->id,
                        'companies_involved' => count($validated['company_products']),
                        'total_products' => $productTotals->count()
                    ]
                ], 201);
            });
        } catch (\Throwable $e) {
            Log::error('Rental job creation failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create rental job',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }


}

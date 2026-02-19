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
use App\Models\Currency;
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
            'company_products.*.private_message' => 'nullable|string',
            'company_products.*.initial_offer' => 'nullable|numeric|min:0'
        ]);

        try {
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
                 * ===========================================
                 * FIX: Aggregate products across all companies
                 * ===========================================
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
                        'company_id' => null // aggregated, so not per company
                    ]);
                }

                /**
                 * ===========================================
                 * Per company supply job creation
                 * ===========================================
                 */
                foreach ($validated['company_products'] as $companyData) {

                    // Create supply job for this company
                    $supplyJob = SupplyJob::create([
                        'rental_job_id' => $rentalJob->id,
                        'provider_id' => $companyData['company_id'],
                        'status' => 'pending'
                    ]);

                    $productsForMail = [];

                    // Requested products per company
                    foreach ($companyData['products'] as $product) {

                        // Find the equipment for this product and company
                        $equipment = Equipment::where('company_id', $companyData['company_id'])
                            ->where('product_id', $product['product_id'])
                            ->first();

                        // Get the price from equipment if available
                        $pricePerUnit = $equipment ? $equipment->price : null;

                        // Supply job product (per supplier)
                        SupplyJobProduct::create([
                            'supply_job_id' => $supplyJob->id,
                            'product_id' => $product['product_id'],
                            'required_quantity' => $product['requested_quantity'],
                            'offered_quantity' => $product['requested_quantity'],
                            'price_per_unit' => $pricePerUnit,
                        ]);

                        // Prepare data for email
                        $productData = Product::with([
                            'getEquipment' => function ($q) use ($companyData) {
                                $q->where('company_id', $companyData['company_id']);
                            }
                        ])->find($product['product_id']);

                        if ($productData) {
                            $productsForMail[] = [
                                'model' => $productData->model,
                                'requested_quantity' => $product['requested_quantity'],
                                'psm_code' => $productData->psm_code,
                                'software_code' => optional($productData->getEquipment)->software_code,
                                'price_per_unit' => $pricePerUnit,
                                'total_price' => $pricePerUnit ? $pricePerUnit * $product['requested_quantity'] : 0,
                            ];
                        }
                    }

                    //Calculate total quote price for this supply job
                    $totalQuotePrice = collect($productsForMail)->sum('total_price');

                    //Update supply job with quote price
                    $supplyJob->update(['quote_price' => $totalQuotePrice]);


                    // Private message per supplier
                    if (!empty($companyData['private_message'])) {
                        RentalJobComment::create([
                            'rental_job_id' => $rentalJob->id,
                            'supply_job_id' => $supplyJob->id,
                            'sender_id' => $user->id,
                            'message' => $companyData['private_message'],
                            'is_private' => true
                        ]);
                    }

                    // Initial offer
                    if (array_key_exists('initial_offer', $companyData) || $totalQuotePrice > 0) {
                        $receiverCurrency = Company::find($companyData['company_id'])->currency_id ?? null;
                        $totalPrice = $companyData['initial_offer'] ?? null;
                        $totalPrice = ($totalPrice && $totalPrice > 0) ? $totalPrice : $totalQuotePrice;
                        JobOffer::create([
                            'rental_job_id' => $rentalJob->id,
                            'supply_job_id' => $supplyJob->id,
                            'sender_company_id' => $user->company_id,
                            'receiver_company_id' => $companyData['company_id'],
                            'version' => 1,
                            'total_price' => $totalPrice,
                            'currency_id' => $receiverCurrency ?? null,
                            'last_offer_by' => $user->company_id,
                            'status' => 'pending'
                        ]);
                    }

                    // Currency symbol
                    $currencySymbol = '';
                    // if ($user->company->currency_id) {
                    //     $currency = Currency::find($user->company->currency_id);
                    //     $currencySymbol = $currency ? $currency->symbol : '';
                    // }
                    if ($receiverCurrency) {
                        $currency = Currency::find($receiverCurrency);
                        $currencySymbol = $currency ? $currency->symbol : $currencySymbol;
                    }
                    /**
                     * ==============================
                     * Email Notification (per company)
                     * ==============================
                     */
                    $company = Company::with('getDefaultcontact')->find($companyData['company_id']);
                    if ($company && $company->getDefaultcontact) {
                        $mailContent = [
                            'rental_name' => $validated['name'],
                            'from_date' => $validated['from_date'],
                            'to_date' => $validated['to_date'],
                            'delivery_address' => $validated['delivery_address'],
                            'offer_requirements' => $validated['offer_requirements'],
                            'global_message' => $validated['global_message'] ?? null,
                            'email' => $user->email,
                            'mobile' => $user->mobile,
                            'company_name' => $company->name,
                            'private_message' => $companyData['private_message'] ?? null,
                            'initial_offer' => $companyData['initial_offer'] ?? null,
                            'currency_symbol' => $currencySymbol,
                            'products' => $productsForMail,

                            // Requesting user details
                            'user_name' => $user->profile->full_name ?? $user->name ?? 'Unknown User',
                            'user_email' => $user->profile->email ?? null,
                            'user_mobile' => $user->profile->mobile ?? null,
                            'user_company' => $user->company->name ?? 'N/A',

                            // Supplier company details
                            'supplier_company_name' => $company->name,
                        ];

                        \App\Helpers\EmailHelper::send('quoteRequest', $mailContent, function ($message) use ($company, $validated) {
                            $message->to($company->getDefaultcontact->email, $validated['name'])
                                ->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }
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

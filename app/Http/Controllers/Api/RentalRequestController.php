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
use App\Models\RentalJobOffer;
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

                //Create rental job
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

                Log::info('Rental job created', ['rental_job_id' => $rentalJob->id]);

                //Process each company and its products
                foreach ($validated['company_products'] as $companyData) {

                    // Supply job for this company
                    $supplyJob = SupplyJob::create([
                        'rental_job_id' => $rentalJob->id,
                        'provider_id' => $companyData['company_id'],
                        'status' => 'pending'
                    ]);

                    $productsForMail = [];

                    // Requested products
                    foreach ($companyData['products'] as $product) {
                        // Supply job product
                        SupplyJobProduct::create([
                            'supply_job_id' => $supplyJob->id,
                            'product_id' => $product['product_id'],
                            'offered_quantity' => 0,
                            'price_per_unit' => null
                        ]);

                        // Rental job product
                        RentalJobProduct::create([
                            'rental_job_id' => $rentalJob->id,
                            'product_id' => $product['product_id'],
                            'requested_quantity' => $product['requested_quantity'],
                            'company_id' => $companyData['company_id']
                        ]);

                        // Prepare data for email (aggregate all product info)
                        $productData = Product::with([
                            'getEquipment' => function ($q) use ($companyData) {
                                $q->where('company_id', '!=', $companyData['company_id']);
                            }
                        ])->find($product['product_id']);

                        if ($productData) {
                            $productsForMail[] = [
                                'model' => $productData->model,
                                'requested_quantity' => $product['requested_quantity'],
                                'psm_code' => $productData->psm_code,
                                'software_code' => optional($productData->getEquipment)->software_code
                            ];
                        }
                    }

                    // Private message (per-company)
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
                    if (isset($companyData['initial_offer'])) {
                        RentalJobOffer::create([
                            'supply_job_id' => $supplyJob->id,
                            'version' => 1,
                            'total_price' => $companyData['initial_offer'],
                            'status' => 'pending'
                        ]);
                    }

                    // Send notification email (only once per company)
                    $company = Company::with('getDefaultcontact')->find($companyData['company_id']);
                    if ($company && $company->getDefaultcontact) {
                        $mailContent = [
                            'rental_name' => $validated['name'],
                            'from_date' => $validated['from_date'],
                            'to_date' => $validated['to_date'],
                            'delivery_address' => $validated['delivery_address'],
                            'offer_requirements' => $validated['offer_requirements'],
                            'email' => $user->email,
                            'mobile' => $user->mobile,
                            'company_name' => $company->name,
                            'private_message' => $companyData['private_message'] ?? null,
                            'initial_offer' => $companyData['initial_offer'] ?? null,
                            'products' => $productsForMail
                        ];

                        Mail::send('emails.quoteRequest', $mailContent, function ($message) use ($company, $validated) {
                            $message->to($company->getDefaultcontact->email, $validated['name'])
                                ->subject('Quote Request from Pro Subrental Marketplace')
                                ->from('acctracking001@gmail.com', 'Pro Subrental Marketplace');
                        });
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Rental job created successfully',
                    'data' => [
                        'rental_job_id' => $rentalJob->id,
                        'companies_involved' => count($validated['company_products']),
                        'total_products' => collect($validated['company_products'])
                            ->flatMap(fn($cp) => $cp['products'])
                            ->count()
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

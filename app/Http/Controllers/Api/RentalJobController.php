<?php

namespace App\Http\Controllers;

use App\Models\RentalJob;
use App\Models\RentalJobProduct;
use App\Models\SupplyJob;
use App\Models\SupplyJobProduct;
use App\Models\RentalJobComment;
use App\Models\RentalJobOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Company;
use App\Models\Product;
use App\Models\Equipment;

class RentalJobController extends Controller
{

    /**
     * LIST: Rental jobs created by the logged-in user (summary).
     * Supports filters + pagination. Secure & light-weight.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Validate query params
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_negotiation', 'accepted', 'cancelled', 'completed'])],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $query = RentalJob::query()
                ->with([
                    // requested products with product + brand
                    'products.product.brand',
                    // count providers via supplyJobs relationship
                    'supplyJobs:id,rental_job_id'
                ])
                ->where('user_id', $user->id);

            // optional filters
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (!empty($validated['from_date'])) {
                $query->whereDate('from_date', '>=', $validated['from_date']);
            }
            if (!empty($validated['to_date'])) {
                $query->whereDate('to_date', '<=', $validated['to_date']);
            }

            $perPage = $validated['per_page'] ?? 10;
            $paginator = $query
                ->orderByDesc('created_at')
                ->paginate($perPage)
                ->appends($request->query()); // keep query params in links

            // Transform payload
            $jobs = collect($paginator->items())->map(function (RentalJob $job) {
                return [
                    'id' => $job->id,
                    'name' => $job->name,
                    'from_date' => $job->from_date,
                    'to_date' => $job->to_date,
                    'delivery_address' => $job->delivery_address,
                    'status' => $job->status,
                    'products' => $job->products->map(function ($rp) {
                        $brand = $rp->product->brand->name ?? '';
                        // product name might be 'name' or 'model' depending on your schema
                        $prod = $rp->product->name ?? $rp->product->model ?? '';
                        return [
                            'id' => $rp->product_id,
                            'name' => trim($brand . ' - ' . $prod, ' -'),
                            'requested_quantity' => (int) $rp->requested_quantity,
                        ];
                    })->values(),
                    'provider_responses_count' => $job->supplyJobs->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'jobs' => $jobs,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rental jobs',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }

    /**
     * DETAIL: Basic rental job details only.
     * Returns basic info + list of suppliers (basic info only).
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user();

        try {
            $job = RentalJob::with([
                'supplyJobs:id,rental_job_id,provider_id,status', // Basic supply job info
                'supplyJobs.providerCompany:id,name', // Company name only
            ])->findOrFail($id);

            // Security: only owner or admin can view
            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rental job.'
                ], 403);
            }

            // Build suppliers array with basic info only
            $suppliers = $job->supplyJobs->map(function ($sj) {
                return [
                    'supply_job_id' => $sj->id,
                    'rental_job_id' => $sj->rental_job_id,
                    'company_id' => $sj->providerCompany->id ?? null,
                    'company_name' => $sj->providerCompany->name ?? 'Unknown',
                    'status' => $sj->status,
                ];
            })->values();

            $payload = [
                'id' => $job->id,
                'name' => $job->name,
                'from_date' => $job->from_date,
                'to_date' => $job->to_date,
                'delivery_address' => $job->delivery_address,
                'status' => $job->status,
                'suppliers' => $suppliers,
            ];

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rental job not found.'
            ], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load rental job',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }

    /**
     * SUPPLIER DETAILS: Get detailed supplier information for a specific supply job.
     * Returns only the products offered by this supplier, with requested vs supplied quantities, pricing, and latest offer.
     */
    public function supplierDetails(Request $request, int $rentalJobId, int $supplyJobId)
    {
        $user = Auth::user();

        try {
            // 1. Verify rental job exists and user has access
            $rentalJob = RentalJob::select(['id', 'user_id'])
                ->findOrFail($rentalJobId);

            if ($rentalJob->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rental job.'
                ], 403);
            }

            // 2. Load supply job with provider, products, offers
            $supplyJob = SupplyJob::with([
                'providerCompany:id,name',
                'products.product' => function ($q) {
                    $q->select('id', 'model', 'brand_id')
                        ->with('brand:id,name');
                },
                'offers' => function ($q) {
                    $q->select('id', 'supply_job_id', 'version', 'total_price', 'status')
                        ->orderByDesc('version');
                },
            ])
                ->where('id', $supplyJobId)
                ->where('rental_job_id', $rentalJobId)
                ->firstOrFail();

            // 3. Fetch requested quantities from rental job products
            $requestedQuantities = RentalJobProduct::where('rental_job_id', $rentalJobId)
                ->pluck('requested_quantity', 'product_id');

            // 4. Build equipment details (only supplier’s products)
            $equipmentDetails = $supplyJob->products->map(function ($supplyProduct) use ($requestedQuantities) {
                $brand = $supplyProduct->product->brand->name ?? '';
                $productName = $supplyProduct->product->model ?? '';

                return [
                    'product_id' => $supplyProduct->product_id,
                    'equipment_name' => trim($brand . ' - ' . $productName, ' -'),
                    'required_quantity' => (int) ($requestedQuantities[$supplyProduct->product_id] ?? 0),
                    'can_supply' => (int) ($supplyProduct->offered_quantity ?? 0),
                    'price_per_unit' => $supplyProduct->price_per_unit !== null ? (float) $supplyProduct->price_per_unit : null,
                ];
            })->values();

            // 5. Get latest offer (if exists)
            $latestOffer = $supplyJob->offers->first();

            // 6. Build response payload
            $payload = [
                'supply_job_id' => $supplyJob->id,
                'rental_job_id' => $supplyJob->rental_job_id,
                'company_id' => $supplyJob->providerCompany->id,
                'company_name' => $supplyJob->providerCompany->name,
                'status' => $supplyJob->status,
                'equipment_details' => $equipmentDetails,
                'latest_offer' => $latestOffer ? [
                    'id' => $latestOffer->id,
                    'version' => (int) $latestOffer->version,
                    'total_price' => (string) $latestOffer->total_price,
                    'status' => $latestOffer->status,
                ] : null,
                'comments_endpoint' => "/api/supply-jobs/{$supplyJob->id}/comments",
            ];

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Log::warning("Supplier details not found", [
                'rental_job_id' => $rentalJobId,
                'supply_job_id' => $supplyJobId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Supply job not found.'
            ], 404);
        } catch (\Throwable $e) {
            \Log::error("Failed to load supplier details", [
                'error' => $e->getMessage(),
                'rental_job_id' => $rentalJobId,
                'supply_job_id' => $supplyJobId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load supplier details.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }


    /**
     * STORE: Create a new rental job with company-product mapping.
     * Enhanced structure to clearly identify which company is responsible for which products.
     */
    public function store(Request $request)
    {
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
            return DB::transaction(function () use ($validated) {
                // 1. Create rental job
                $rentalJob = RentalJob::create([
                    'user_id' => auth()->id(),
                    'name' => $validated['name'],
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date'],
                    'delivery_address' => $validated['delivery_address'],
                    'offer_requirements' => $validated['offer_requirements'] ?? null,
                    'global_message' => $validated['global_message'] ?? null,
                    'status' => 'open'
                ]);

                // 2. Create supply jobs and products for each company
                foreach ($validated['company_products'] as $companyData) {
                    // Create supply job for this company
                    $supplyJob = SupplyJob::create([
                        'rental_job_id' => $rentalJob->id,
                        'provider_id' => $companyData['company_id'],
                        'status' => 'pending'
                    ]);

                    // Create supply job products for this company
                    foreach ($companyData['products'] as $product) {
                        SupplyJobProduct::create([
                            'supply_job_id' => $supplyJob->id,
                            'product_id' => $product['product_id'],
                            'offered_quantity' => 0, // Company will update this
                            'price_per_unit' => null // Company will set this
                        ]);
                    }

                    // Create rental job products (what user requested) with company mapping
                    foreach ($companyData['products'] as $product) {
                        RentalJobProduct::create([
                            'rental_job_id' => $rentalJob->id,
                            'product_id' => $product['product_id'],
                            'requested_quantity' => $product['requested_quantity'],
                            'company_id' => $companyData['company_id'] // Track which company is responsible
                        ]);
                    }

                    // Private message for this company
                    if (!empty($companyData['private_message'])) {
                        RentalJobComment::create([
                            'rental_job_id' => $rentalJob->id,
                            'supply_job_id' => $supplyJob->id,
                            'sender_id' => auth()->id(),
                            'message' => $companyData['private_message'],
                            'is_private' => true
                        ]);
                    }

                    // Initial offer for this company
                    if (isset($companyData['initial_offer'])) {
                        RentalJobOffer::create([
                            'supply_job_id' => $supplyJob->id,
                            'version' => 1,
                            'total_price' => $companyData['initial_offer'],
                            'status' => 'pending'
                        ]);
                    }
                }

                $user_data = UserProfile::where('user_id',auth()->id())->first();
                $mail_content = [];
                $mail_content['rental_name'] = $validated['name'];
                $mail_content['from_date'] = $validated['from_date'];
                $mail_content['to_date'] = $validated['to_date'];
                $mail_content['offer_requirements'] = $validated['offer_requirements'];
                $mail_content['delivery_address'] = $validated['delivery_address'];
                $mail_content['email'] = $user_data->email;
                $mail_content['mobile'] = $user_data->mobile;

                  foreach ($validated['company_products'] as $companyData) {
                    $company_id = $companyData['company_id'];
                            $company_data = Company::with(['getDefaultcontact'])->where('id', $company_id)->first();
                            //  print_r($company_data);
                            $mail_content['company_name'] = $company_data->name; 
                            $mail_content['private_message'] = $companyData['private_message']; 
                            $mail_content['initial_offer'] = $companyData['initial_offer']; 
                            $to_email = ($company_data->getDefaultcontact)->email;
                            $to_name = $validated['name'];
                    
                    foreach ($companyData['products'] as $product) {
                        $product_data = Product::with(['getEquipment' => function($q) use($company_id) {$q->where('company_id', '!=', $company_id);}])->where('id', $product['product_id'])->first();
                        // print_r($product_data);
                        
                        $mail_content['model'] = $product_data->model;
                        $mail_content['requested_quantity'] = $product['requested_quantity']; 
                        $mail_content['psm_code'] = $product_data->psm_code; 
                        $mail_content['software_code'] = ($product_data->getEquipment)->software_code;  

                        Mail::send('emails.quoteRequest', $mail_content, function ($message) use ($to_name, $to_email) {
                        $message->to($to_email, $to_name)
                            ->subject('Quote Request from Pro Subrental Marketplace');
                        $message->from('acctracking001@gmail.com', 'Pro Subrental Marketplace'); 
                        });
                    }

                }

                return response()->json([
                    'success' => true,
                    'rental_job_id' => $rentalJob->id,
                    'message' => 'Rental job created successfully',
                    'data' => [
                        'rental_job' => $rentalJob,
                        'companies_involved' => count($validated['company_products']),
                        'total_products' => collect($validated['company_products'])
                            ->flatMap(fn($cp) => $cp['products'])
                            ->count()
                    ]
                ], 201);
            });
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create rental job',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }



}

//  $mail_content = [];
//         $mail_content['description'] = $data->description;
//         $mail_content['name'] = $user_data->name;
//         $mail_content['date'] = $data->start_date;
//         $mail_content['close_date'] = $data->closed_date;
//         $mail_content['add_comments'] = $request->add_comments;
//         $to_name = 'Lugg';
//         $to_email = $user_data->email;
      
//         $to_email = "acctracking001@gmail.com";

//         Mail::send('mail.ticketmail', $mail_content, function ($message) use ($to_name, $to_email) {
//             $message->to($to_email, $to_name)
//                 ->subject('Lugg - Your Ticket confirmed');
//             $message->from('acctracking001@gmail.com', 'Lugg');
//         });
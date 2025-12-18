<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Equipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewProductCreated;
use App\Notifications\ImportedProductsCreated;
use Illuminate\Support\Facades\Validator;
use App\Notifications\DuplicateProductMerged;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use App\Models\SavedProduct;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\CreateOrAttachRequest;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;

class ProductController extends Controller
{

    public function search(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2'
        ]);

        $searchTerm = trim($request->query('search'));
        $keywords = array_filter(explode(' ', $searchTerm)); // split into words and remove empty strings

        // If no keywords after filtering, return empty
        if (empty($keywords)) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->select(
                'p.id as product_id',
                DB::raw("TRIM(CONCAT_WS(' ', b.name, p.model)) as product_name"),
                'p.model as model_name',
                'p.psm_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name'
            )
            ->where(function ($query) use ($keywords) {
                // Ensure all keywords match somewhere (AND logic) - case-insensitive
                foreach ($keywords as $word) {
                    $wordLower = strtolower(trim($word));
                    if (empty($wordLower)) {
                        continue;
                    }
                    $wordLike = '%' . $wordLower . '%';
                    $query->where(function ($q) use ($wordLike) {
                        $q->whereRaw('LOWER(p.model) LIKE ?', [$wordLike])
                            ->orWhereRaw('LOWER(b.name) LIKE ?', [$wordLike])
                            ->orWhereRaw('LOWER(c.name) LIKE ?', [$wordLike])
                            ->orWhereRaw('LOWER(sc.name) LIKE ?', [$wordLike])
                            ->orWhereRaw('LOWER(TRIM(CONCAT_WS(\' \', b.name, p.model))) LIKE ?', [$wordLike]);
                    });
                }
            })
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function searchOld(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2'
        ]);

        $search = '%' . $request->query('search') . '%';

        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->select(
                'p.id as product_id',
                DB::raw("CONCAT(b.name, ' ', p.model) as product_name"), // 👈 concat brand + model
                'p.model as model_name',
                'p.psm_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name'
            )
            // ->where(function ($query) use ($search) {
            //     $query->Where('p.model', 'LIKE', $search)
            //         ->orWhere('b.name', 'LIKE', $search)
            //         ->orWhere('c.name', 'LIKE', $search)
            //         ->orWhere('sc.name', 'LIKE', $search);
            // })
            ->where(function ($query) use ($search) {
                $query->where('p.model', 'LIKE', $search)
                    ->orWhere('b.name', 'LIKE', $search)
                    ->orWhere('c.name', 'LIKE', $search)
                    ->orWhere('sc.name', 'LIKE', $search)
                    ->orWhere(DB::raw("CONCAT(b.name, ' ', p.model)"), 'LIKE', $search);
            })
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function createOrAttach(CreateOrAttachRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = JWTAuth::parseToken()->authenticate();

        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.'
            ], 404);
        }

        if (strtolower($user->account_type) !== 'provider') {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'ACCOUNT_NOT_PROVIDER',
                    'message' => 'Only provider accounts are allowed to perform this action.'
                ]
            ], 403);
        }


        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'quantity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'rental_software_code' => 'nullable|string|max:255',
            'webpage_url' => 'nullable|url|max:255',
        ]);


        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();


        DB::beginTransaction();

        try {
            /** ---------------------------------------------------------
             * 1️⃣ Use Only Existing IDs (No Auto-Create)
             * --------------------------------------------------------- */
            $categoryId = $validated['category_id'] ?? null;
            $subCategoryId = $validated['sub_category_id'] ?? null;
            $brandId = $validated['brand_id'] ?? null;


            /**Handle Product — advanced duplicate detection */
            $productName = trim($validated['name']);
            $normalizedName = $this->normalizeProductName($productName);

            // Check for existing products with similar names
            $existingProduct = $this->findSimilarProduct($normalizedName, $brandId);

            if ($existingProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists',
                    'error' => 'A product with a similar name already exists in the system.',
                    'data' => [
                        'existing_product' => [
                            'id' => $existingProduct->id,
                            'model' => $existingProduct->model,
                            'psm_code' => $existingProduct->psm_code,
                            'brand' => $existingProduct->brand->name ?? 'N/A',
                            'category' => $existingProduct->category->name ?? 'N/A',
                        ],
                        'suggested_action' => 'Please use the existing product or modify the name to make it unique.'
                    ]
                ], 409); // HTTP 409 Conflict
            }


            /** ---------------------------------------------------------
             * 2️⃣ Always Create a New Product
             * --------------------------------------------------------- */
            $productName = trim($validated['name']);
            $psmCode = $validated['psm_code'] ?? $this->generateNextPsmCode();

            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand_id' => $brandId,
                'model' => $productName,
                'psm_code' => $psmCode,
                'webpage_url' => $validated['webpage_url'] ?? null,
                'is_verified' => 0,
            ]);

            /** ---------------------------------------------------------
             * 3️⃣ Create or Update Equipment for User's Company
             * --------------------------------------------------------- */
            Equipment::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $validated['quantity'],
                    'price' => $validated['price'],
                    'software_code' => $validated['rental_software_code'],
                ]
            );

            DB::commit();

            /** ---------------------------------------------------------
             * 4️⃣ Send Email to App Admin
             * --------------------------------------------------------- */

            Notification::route('mail', config('mail.admin.address'))
                ->notify(new NewProductCreated($product, $user));


            return response()->json([
                'success' => true,
                'message' => 'New product and equipment created successfully.',
                'data' => [
                    'product' => $product,
                    'is_verified' => 0,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function mergeDuplicateProductIntoOriginal(Product $duplicate, Product $original)
    {
        // All tables referencing product_id
        $tables = $this->tablesReferencingProduct();

        foreach ($tables as $table => $column) {
            DB::table($table)
                ->where($column, $duplicate->id)
                ->update([$column => $original->id]);
        }

        $duplicate->delete();
    }

    private function tablesReferencingProduct()
    {
        return [
            'equipments' => 'product_id',
            'rental_job_products' => 'product_id',
            'supply_job_products' => 'product_id',
            // Add more tables if required
        ];
    }

    public function createOrAttachOld(CreateOrAttachRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        DB::beginTransaction();

        try {
            /** 1️⃣ Handle Category */
            $categoryId = $validated['category_id'] ?? null;
            if (isset($validated['category']['is_new']) && $validated['category']['is_new']) {
                $category = Category::firstOrCreate(['name' => trim($validated['category']['name'])]);
                $categoryId = $category->id;
            }

            /** 2️⃣ Handle SubCategory */
            $subCategoryId = $validated['sub_category_id'] ?? null;
            if (isset($validated['sub_category']['is_new']) && $validated['sub_category']['is_new']) {
                $subCategory = SubCategory::firstOrCreate([
                    'name' => trim($validated['sub_category']['name']),
                    'category_id' => $categoryId,
                ]);
                $subCategoryId = $subCategory->id;
            }

            /** 3️⃣ Handle Brand */
            $brandId = $validated['brand_id'] ?? null;
            if (isset($validated['brand']['is_new']) && $validated['brand']['is_new']) {
                $brand = Brand::firstOrCreate(['name' => trim($validated['brand']['name'])]);
                $brandId = $brand->id;
            }

            /** 4️⃣ Handle Product — advanced duplicate detection */
            $productName = trim($validated['name']);
            $normalizedName = $this->normalizeProductName($productName);

            // Check for existing products with similar names
            $existingProduct = $this->findSimilarProduct($normalizedName, $brandId);

            if ($existingProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists',
                    'error' => 'A product with a similar name already exists in the system.',
                    'data' => [
                        'existing_product' => [
                            'id' => $existingProduct->id,
                            'model' => $existingProduct->model,
                            'psm_code' => $existingProduct->psm_code,
                            'brand' => $existingProduct->brand->name ?? 'N/A',
                            'category' => $existingProduct->category->name ?? 'N/A',
                        ],
                        'suggested_action' => 'Please use the existing product or modify the name to make it unique.'
                    ]
                ], 409); // HTTP 409 Conflict
            }

            // Generate safe next PSM code (always PSM format)
            $psmCode = $validated['psm_code'] ?? $this->generateNextPsmCode();

            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand_id' => $brandId,
                'model' => $productName,
                'psm_code' => $psmCode,
                'is_verified' => 0,
            ]);

            $isVerified = 0;

            /** 5️⃣ Attach or update Equipment */
            Equipment::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $validated['quantity'],
                    'price' => $validated['price'],
                    'software_code' => $validated['rental_software_code'],
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'New product and equipment created successfully.',
                'data' => [
                    'product' => $product,
                    'is_verified' => $isVerified,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create or attach product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function importProducts(Request $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();

        Log::info('user details : ' . json_encode($user));
        Log::info('User account type: ' . $user->account_type);
        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.'
            ], 404);
        }

        if (strtolower($user->account_type) !== 'provider') {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'ACCOUNT_NOT_PROVIDER',
                    'message' => 'Only provider accounts are allowed to perform this action.'
                ]
            ], 403);
        }

        // Validate file
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480'
        ]);

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to read import file',
                'error' => $e->getMessage()
            ], 422);
        }

        // ✅ ENFORCE 100 ROW LIMIT
        $dataRowCount = max(0, count($rows) - 1); // Exclude header row
        if ($dataRowCount > 100) {
            return response()->json([
                'success' => false,
                'message' => "Maximum 100 rows allowed per upload. Your file contains {$dataRowCount} data rows.",
            ], 422);
        }

        // Counters + accumulators
        $createdProducts = [];
        $attachedCount = 0;
        $duplicateCount = 0;
        $errorRows = [];

        DB::beginTransaction();

        try {
            // Start from row 2 because row 1 contains column headings
            foreach ($rows as $index => $row) {
                if ($index == 1) {
                    continue;
                }

                $quantity = $row['A'];
                $description = $row['B'];
                $softwareCode = $row['C'];

                // Skip blank rows
                if (!$description || trim($description) == '') {
                    continue;
                }

                $productName = trim($description);
                $normalizedName = $this->normalizeProductName($productName);

                // Detect duplicates (same method as createOrAttach)
                $existingProduct = $this->findSimilarProduct($normalizedName, null);

                if ($existingProduct) {
                    // Attach equipment to logged-in user/company
                    Equipment::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'company_id' => $user->company_id,
                            'product_id' => $existingProduct->id,
                        ],
                        [
                            'quantity' => $quantity,
                            'price' => null,
                            'software_code' => $softwareCode,
                        ]
                    );

                    $duplicateCount++;
                    continue;
                }

                // Create new product
                try {
                    $psmCode = $this->generateNextPsmCode();

                    Log::info('Generated PSM Code: ' . $psmCode . ' for product: ' . $productName);

                    $product = Product::create([
                        'category_id' => null,
                        'sub_category_id' => null,
                        'brand_id' => null,
                        'model' => $productName,
                        'psm_code' => $psmCode,
                        'webpage_url' => null,
                        'is_verified' => 0,
                    ]);

                    Log::info('products details : ' . json_encode($product));


                    // Attach equipment
                    Equipment::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'company_id' => $user->company_id,
                            'product_id' => $product->id,
                        ],
                        [
                            'quantity' => $quantity,
                            'price' => null,
                            'software_code' => $softwareCode,
                        ]
                    );

                    // Fetch equipment with software_code included
                    $product->load([
                        'equipments' => function ($query) use ($user) {
                            $query->where('user_id', $user->id);
                        }
                    ]);

                    Log::info('products details : ' . json_encode($product));

                    $createdProducts[] = $product;

                } catch (\Throwable $rowError) {
                    $errorRows[] = [
                        'row' => $index,
                        'description' => $description,
                        'error' => $rowError->getMessage()
                    ];
                    continue;
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Import failed',
                'error' => $e->getMessage()
            ], 500);
        }

        /**
         * Send email to admin with list of newly created products.
         * Only send if at least one new product is created
         */
        if (count($createdProducts) > 0) {
            Notification::route('mail', config('mail.admin.address'))
                ->notify(new ImportedProductsCreated(
                    $createdProducts,    // array
                    $user->profile->full_name ?? 'N/A',
                    $user->email,
                    $user->company->name ?? 'N/A',
                ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Import completed.',
            'summary' => [
                'created' => count($createdProducts),
                'attached' => $attachedCount,
                'duplicates_detected' => $duplicateCount,
                'errors' => $errorRows
            ],
            'created_products' => $createdProducts
        ], 200);
    }

    /**
     * Normalize product name for comparison
     * Handles case-insensitive, word-order independent comparison
     */
    protected function normalizeProductName(string $productName): string
    {
        // Convert to lowercase
        $normalized = strtolower($productName);

        // Remove extra spaces and normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        // Split into words, sort them, and rejoin
        $words = explode(' ', $normalized);
        sort($words);

        // Remove common words that don't add uniqueness
        $commonWords = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by'];
        $words = array_filter($words, function ($word) use ($commonWords) {
            return !in_array($word, $commonWords) && strlen($word) > 1;
        });

        return implode(' ', $words);
    }

    /**
     * Find similar products based on normalized name and brand
     */
    protected function findSimilarProduct(string $normalizedName, ?int $brandId): ?Product
    {
        // Get all products with their relationships
        $query = Product::with(['brand', 'category', 'subCategory']);

        // If brand is specified, prioritize products from the same brand
        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        $products = $query->get();

        foreach ($products as $product) {
            $existingNormalized = $this->normalizeProductName($product->model);

            // Check for exact match after normalization
            if ($existingNormalized === $normalizedName) {
                return $product;
            }

            // Check for high similarity (fuzzy matching)
            $similarity = $this->calculateSimilarity($normalizedName, $existingNormalized);
            if ($similarity >= 0.85) { // 85% similarity threshold
                return $product;
            }
        }

        return null;
    }

    /**
     * Calculate similarity between two normalized product names
     */
    protected function calculateSimilarity(string $name1, string $name2): float
    {
        // Convert to arrays of words
        $words1 = explode(' ', $name1);
        $words2 = explode(' ', $name2);

        // Calculate Jaccard similarity
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        if (empty($union)) {
            return 0;
        }

        return count($intersection) / count($union);
    }

    /**
     * Generate the next sequential PSM code safely (always PSM format)
     */
    protected function generateNextPsmCode(): string
    {
        $latest = Product::select('psm_code')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($latest && preg_match('/PSM(\d+)/', $latest->psm_code, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        } else {
            $nextNumber = 1;
        }

        return 'PSM' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    public function createOrAttachOld1(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],

            'category.is_new' => ['nullable', 'boolean'],
            'category.name' => ['required_if:category.is_new,true', 'string', 'max:255'],

            'sub_category.is_new' => ['nullable', 'boolean'],
            'sub_category.name' => ['required_if:sub_category.is_new,true', 'string', 'max:255'],

            'brand.is_new' => ['nullable', 'boolean'],
            'brand.name' => ['required_if:brand.is_new,true', 'string', 'max:255'],

            'name' => ['required', 'string', 'max:255'],
            'psm_code' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'rental_software_code' => ['required', 'string', 'max:255'],
        ]);

        DB::beginTransaction();
        try {
            // 1. Handle Category
            $categoryId = $validated['category_id'] ?? null;
            if (isset($validated['category']['is_new']) && $validated['category']['is_new']) {
                $category = Category::create(['name' => $validated['category']['name']]);
                $categoryId = $category->id;
            }

            // 2. Handle SubCategory
            $subCategoryId = $validated['sub_category_id'] ?? null;
            if (isset($validated['sub_category']['is_new']) && $validated['sub_category']['is_new']) {
                $subCategory = SubCategory::create([
                    'name' => $validated['sub_category']['name'],
                    'category_id' => $categoryId,
                ]);
                $subCategoryId = $subCategory->id;
            }

            // 3. Handle Brand
            $brandId = $validated['brand_id'] ?? null;
            if (isset($validated['brand']['is_new']) && $validated['brand']['is_new']) {
                $brand = Brand::create(['name' => $validated['brand']['name']]);
                $brandId = $brand->id;
            }

            // 4. Create Product (is_verified = 0 always)
            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand_id' => $brandId,
                'model' => $validated['name'],
                'psm_code' => $validated['psm_code'],
                // 'rental_software_code' => $validated['rental_software_code'],
                'is_verified' => 0,
            ]);

            // 5. Create Equipment for the user's company
            $user = Auth::user();
            Equipment::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id, // assumes User has company_id field
                'product_id' => $product->id,
                'quantity' => $validated['quantity'],
                'price' => $validated['price'],
                'software_code' => $validated['rental_software_code'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product and equipment created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

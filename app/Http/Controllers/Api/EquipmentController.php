<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

class EquipmentController extends Controller
{
    /**
     * Authenticate user from JWT
     */
    private function getAuthenticatedUser()
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            Log::warning('JWT authentication failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store new equipment
     */
    public function store(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        // -------------------------------
        // 🔹 Validation
        // -------------------------------
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            // -------------------------------
            // 🔹 Check Duplicate Equipment
            // -------------------------------
            $existing = Equipment::where('company_id', $user->company_id)
                ->where('product_id', $request->product_id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'This equipment already exists for your company. Duplicate entries are not allowed.',
                    'data' => [
                        'existing_equipment_id' => $existing->id,
                        'product_id' => $existing->product_id
                    ]
                ], 409);
            }

            // -------------------------------
            // 🔹 Create Equipment
            // -------------------------------
            $equipment = Equipment::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $request->price,
                'software_code' => $request->software_code,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Equipment added successfully',
                'data' => $equipment
            ], 201);

        } catch (\Exception $e) {

            Log::error('Equipment store failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error saving equipment'
            ], 500);
        }
    }

    /**
     * Get company equipments
     */
    public function getCompanyEquipments(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not belong to any company.'
                ], 404);
            }

            $search = $request->input('search', null);

            $query = Equipment::with(['product.brand', 'images'])
                ->where('company_id', $user->company_id);

            if ($search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('model', 'like', "%$search%")
                        ->orWhereHas('brand', fn($b) => $b->where('name', 'like', "%$search%"));
                });
            }

            // Get all records instead of paginate
            $equipments = $query->get();

            // Format only needed values
            // $formatted = $equipments->map(function ($equipment) {
            //     return [
            //         'id' => $equipment->id,
            //         'product_label' => $equipment->product->brand->name . ' - ' . $equipment->product->model,
            //         'psm_code' => $equipment->product->psm_code,
            //         'software_code' => $equipment->software_code,
            //         'quantity' => $equipment->quantity,
            //         'price' => $equipment->price,
            //         'description' => $equipment->description,
            //         'images' => $equipment->images->pluck('image_path')
            //     ];
            // });

            $formatted = $equipments->map(function ($equipment) {
                $product   = $equipment->product;
                $brandName = $product->brand->name ?? null;
                $modelName = $product->model ?? 'Unknown Model';

                return [
                    'id' => $equipment->id,
                    'product_id' => $product->id,
                    'product_label' => $brandName
                        ? "{$brandName} - {$modelName}"
                        : $modelName, // show only model if brand is missing
                    'psm_code' => $product->psm_code,
                    'webpage_url' => $product->webpage_url, // 🔗 product webpage URL
                    'software_code' => $equipment->software_code,
                    'quantity' => $equipment->quantity,
                    'price' => $equipment->price,
                    'description' => $equipment->description,
                    'is_verified' => $product->is_verified,
                    'images' => $equipment->images->map(function ($img) {
                        return [
                            'id' => $img->id,
                            // 'path' => $img->image_path,
                            'url' => asset($img->image_path)
                        ];
                    })
                ];
            });


            return response()->json([
                'success' => true,
                'message' => 'Company equipments fetched successfully',
                'total' => $equipments->count(),
                'data' => $formatted
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching company equipments', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch company equipments'
            ], 500);
        }
    }

    /**
     * Update quantity
     */
    public function updateQuantity(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $validator = Validator::make($request->all(), ['quantity' => 'required|integer|min:1']);
        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $equipment->update(['quantity' => $request->quantity]);

        return response()->json(['success' => true, 'message' => 'Quantity updated', 'data' => $equipment]);
    }

    /**
     * Update price
     */
    public function updatePrice(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $validator = Validator::make($request->all(), ['price' => 'required|numeric|min:0']);
        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $equipment->update(['price' => $request->price]);

        return response()->json(['success' => true, 'message' => 'Price updated', 'data' => $equipment]);
    }

    /**
     * Update software code
     */
    public function updateSoftwareCode(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $validator = Validator::make($request->all(), ['software_code' => 'nullable|string|max:255']);
        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $equipment->update(['software_code' => $request->software_code]);

        return response()->json(['success' => true, 'message' => 'Software code updated', 'data' => $equipment]);
    }

    /**
     * Update description
     */
    public function updateDescription(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $validator = Validator::make($request->all(), ['description' => 'nullable|string|max:1000']);
        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $equipment->update(['description' => $request->description]);

        return response()->json(['success' => true, 'message' => 'Description updated', 'data' => $equipment]);
    }

    public function addImages(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'images.*' => 'required|image|max:2048',
            'image_ids.*' => 'nullable|integer|exists:equipment_images,id', // for replacement
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if (!$request->hasFile('images')) {
            return response()->json(['success' => false, 'message' => 'No images uploaded'], 422);
        }

        $destinationPath = public_path('images/equipment_image');
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $uploaded = [];
        $imageFiles = $request->file('images');
        $imageIds = $request->input('image_ids', []); // optional replacement IDs

        foreach ($imageFiles as $index => $file) {
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($destinationPath, $filename);
            $relativePath = 'images/equipment_image/' . $filename;

            // Check if updating an existing image
            $existingImageId = $imageIds[$index] ?? null;
            if ($existingImageId) {
                $existingImage = EquipmentImage::find($existingImageId);
                if ($existingImage && $existingImage->equipment_id === $equipment->id) {
                    // Delete old image file if exists
                    $oldFilePath = public_path($existingImage->image_path);
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }

                    // Update DB record
                    $existingImage->update(['image_path' => $relativePath]);

                    $uploaded[] = [
                        'id' => $existingImage->id,
                        'url' => asset($relativePath),
                        'message' => 'Image updated successfully',
                    ];
                    continue;
                }
            }

            // Create new image record
            $img = EquipmentImage::create([
                'equipment_id' => $equipment->id,
                'image_path' => $relativePath,
            ]);

            $uploaded[] = [
                'id' => $img->id,
                'url' => asset($relativePath),
                'message' => 'Image uploaded successfully',
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Images processed successfully',
            'data' => $uploaded
        ]);
    }

    /**
     * Delete image
     */
    public function deleteImage($id)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $image = EquipmentImage::find($id);
        if (!$image) {
            return response()->json(['success' => false, 'message' => 'Image not found'], 404);
        }

        if ($image->equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $filePath = public_path($image->image_path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $image->delete();

        return response()->json(['success' => true, 'message' => 'Image deleted successfully']);
    }

    /**
     * Delete equipment
     */
    public function destroy(Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        foreach ($equipment->images as $img) {
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }

        $equipment->delete();

        return response()->json(['success' => true, 'message' => 'Equipment deleted']);
    }
}

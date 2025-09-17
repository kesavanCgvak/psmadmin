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

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $equipment = Equipment::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $request->price,
                'software_code' => $request->software_code,
            ]);

            return response()->json(['success' => true, 'message' => 'Equipment added successfully', 'data' => $equipment], 201);
        } catch (\Exception $e) {
            Log::error('Equipment store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error saving equipment'], 500);
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
            $formatted = $equipments->map(function ($equipment) {
                return [
                    'id' => $equipment->id,
                    'product_label' => $equipment->product->brand->name . ' - ' . $equipment->product->model,
                    'psm_code' => $equipment->product->psm_code,
                    'software_code' => $equipment->software_code,
                    'quantity' => $equipment->quantity,
                    'price' => $equipment->price,
                    'description' => $equipment->description,
                    'images' => $equipment->images->pluck('image_path')
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Company equipments fetched successfully',
                'total'   => $equipments->count(),
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

    /**
     * Add images
     */
    public function addImages(Request $request, Equipment $equipment)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        if ($equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), ['images.*' => 'required|image|max:2048']);
        if ($validator->fails())
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        $uploaded = [];
        foreach ($request->file('images') as $file) {
            $path = $file->store('equipment_images', 'public');
            $img = EquipmentImage::create(['equipment_id' => $equipment->id, 'image_path' => $path]);
            $uploaded[] = $img;
        }

        return response()->json(['success' => true, 'message' => 'Images added', 'data' => $uploaded]);
    }

    /**
     * Delete image
     */
    public function deleteImage($id)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user)
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $image = EquipmentImage::find($id);
        if (!$image)
            return response()->json(['success' => false, 'message' => 'Image not found'], 404);

        if ($image->equipment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json(['success' => true, 'message' => 'Image deleted']);
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

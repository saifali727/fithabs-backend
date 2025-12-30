<?php

namespace App\Http\Controllers;

use App\Models\Coach;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CoachController extends Controller
{
    public function index()
    {
        try {
            $coaches = Coach::where('is_active', true)
                ->get()
                ->map(function($coach) {
                    return [
                        'id' => $coach->id,
                        'name' => $coach->name,
                        'bio' => $coach->bio,
                        'profile_image' => $coach->profile_image,
                        'specializations' => $coach->specializations,
                        'certifications' => $coach->certifications,
                        'phone' => $coach->phone,
                        'chat_url' => "/api/v1/coaches/{$coach->id}/chat"
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $coaches,
                'count' => $coaches->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve coaches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function adminIndex(Request $request)
    {
        try {
            $query = Coach::query();

            // Search functionality
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('bio', 'like', "%{$search}%")
                      ->orWhereJsonContains('specializations', $search);
                });
            }

            // Filter by specialization
            if ($request->has('specialization')) {
                $query->whereJsonContains('specializations', $request->get('specialization'));
            }

            // Filter by status
            if ($request->has('status')) {
                $status = $request->get('status');
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
                // 'all' shows both active and inactive
            } else {
                // Default to showing all for admin
                $query->where('is_active', true);
            }

            // Pagination
            $perPage = min($request->get('per_page', 15), 100);
            $coaches = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json($coaches, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve coaches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $coach = Coach::findOrFail($id);
        return response()->json($coach, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:coaches',
            'password' => 'required|string|min:8',
            'bio' => 'nullable|string',
            'profile_image' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp|max:10240', // 10MB max
            'specializations' => 'nullable|array',
            'certifications' => 'nullable|array',
            'phone' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            $profileImageUrl = null;
            
            // Handle image upload if provided
            if ($request->hasFile('profile_image')) {
                $profileImageUrl = $this->handleImageUpload($request->file('profile_image'), 'profile');
            } elseif ($request->has('profile_image') && is_string($request->profile_image)) {
                $profileImageUrl = $request->profile_image;
            }

            // Create User account first
            $user = \App\Models\User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'coach',
                'phone' => $request->phone,
            ]);

            // Create Coach profile linked to User
            $coach = Coach::create([
                'user_id' => $user->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password), // Keeping for backward compatibility temporarily
                'bio' => $request->bio,
                'profile_image' => $profileImageUrl,
                'specializations' => $request->specializations,
                'certifications' => $request->certifications,
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? true,
            ]);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Coach created successfully',
                'data' => $coach->load('user'),
            ], 201);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create coach: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $coach = Coach::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:coaches,email,'.$id,
            'password' => 'sometimes|string|min:8',
            'bio' => 'nullable|string',
            'profile_image' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp|max:10240', // 10MB max
            'specializations' => 'nullable|array',
            'certifications' => 'nullable|array',
            'phone' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
        $data = $request->only([
                'name', 'email', 'bio', 'specializations',
            'certifications', 'phone', 'is_active'
        ]);

            // Handle image upload if provided
            if ($request->hasFile('profile_image')) {
                $data['profile_image'] = $this->handleImageUpload($request->file('profile_image'), 'profile');
            } elseif ($request->has('profile_image') && is_string($request->profile_image)) {
                $data['profile_image'] = $request->profile_image;
            }

        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $coach->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Coach updated successfully',
                'data' => $coach,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update coach: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
        $coach = Coach::findOrFail($id);
        $coach->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Coach deleted successfully',
            ], 204);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete coach: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleImageUpload($file, $category = 'profile')
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        
        $filename = Str::uuid() . '.' . $extension;
        
        $path = $file->storeAs('', $filename, 'images');
        
        // Generate the correct URL based on the current request
        $baseUrl = request()->getSchemeAndHttpHost();
        $url = $baseUrl . '/storage/images/' . $filename;

        $imageInfo = getimagesize($file->getPathname());
        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        Image::create([
            'title' => $originalName,
            'description' => 'Uploaded image for ' . $category,
            'filename' => $originalName,
            'path' => $path,
            'url' => $url,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'category' => $category,
            'uploaded_by' => auth()->id(),
        ]);

        return $url;
    }
}
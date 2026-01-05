<?php

namespace App\Http\Controllers;

use App\Models\UserFavorite;
use App\Models\Workout;
use App\Models\EducationContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserFavoriteController extends Controller
{
    /**
     * Get favorites for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $favorites = UserFavorite::with(['favoritable'])
                ->where('user_id', $user->id)
                ->whereIn('favoritable_type', ['workout', 'education_content'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Transform favorites to include complete item details
            $transformedFavorites = $favorites->map(function ($favorite) {
                $item = $favorite->favoritable;
                
                // Skip if the favorited item no longer exists
                if (!$item) {
                    return null;
                }
                
                if ($favorite->favoritable_type === 'workout') {
                    // Load exercises for workout
                    $item->load(['exercises' => function($q) {
                        $q->orderBy('order');
                    }]);
                    
                    // Add computed fields like in WorkoutController
                    $item->total_exercises = $item->exercises->count();
                    $item->total_sets = $item->exercises->sum('sets');
                    $item->estimated_duration = $item->exercises->sum(function($exercise) {
                        return ($exercise->duration_seconds ?? 0) + ($exercise->rest_seconds ?? 0);
                    });
                    
                    return [
                        'id' => $favorite->id,
                        'type' => 'workout',
                        'item' => $item,
                        'created_at' => $favorite->created_at,
                    ];
                } else {
                    // Transform education content like in EducationContentController
                    $transformedItem = [
                        'id' => $item->id,
                        'coverImage' => $item->image_url,
                        'title' => $item->title,
                        'description' => $item->description,
                        'sections' => $item->sections,
                        'category' => $item->category,
                        'tags' => $item->tags,
                        'is_featured' => $item->is_featured,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ];
                    
                    return [
                        'id' => $favorite->id,
                        'type' => 'education_content',
                        'item' => $transformedItem,
                        'created_at' => $favorite->created_at,
                    ];
                }
            })->filter();

            return response()->json([
                'status' => 'success',
                'data' => $transformedFavorites,
                'count' => $transformedFavorites->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add item to favorites
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            $validator = Validator::make($request->all(), [
                'favoritable_type' => 'required|in:workout,education_content',
                'favoritable_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if item exists
            $modelClass = $request->favoritable_type === 'workout' ? Workout::class : EducationContent::class;
            $item = $modelClass::find($request->favoritable_id);
            
            if (!$item) {
                return response()->json([
                    'status' => 'error',
                    'message' => ucfirst($request->favoritable_type) . ' not found'
                ], 404);
            }

            // Check if already favorited
            $existingFavorite = UserFavorite::where('user_id', $user->id)
                ->where('favoritable_type', $request->favoritable_type)
                ->where('favoritable_id', $request->favoritable_id)
                ->first();

            if ($existingFavorite) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Item already in favorites'
                ], 409);
            }

            $favorite = UserFavorite::create([
                'user_id' => $user->id,
                'favoritable_type' => $request->favoritable_type,
                'favoritable_id' => $request->favoritable_id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Added to favorites',
                'data' => $favorite
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add to favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove item from favorites
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $favorite = UserFavorite::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$favorite) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Favorite not found'
                ], 404);
            }

            $favorite->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Removed from favorites'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove from favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove favorite by item type and ID
     */
    public function removeByItem(Request $request)
    {
        try {
            $user = $request->user();
            
            $validator = Validator::make($request->all(), [
                'favoritable_type' => 'required|in:workout,education_content',
                'favoritable_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $favorite = UserFavorite::where('user_id', $user->id)
                ->where('favoritable_type', $request->favoritable_type)
                ->where('favoritable_id', $request->favoritable_id)
                ->first();

            if (!$favorite) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Favorite not found'
                ], 404);
            }

            $favorite->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Removed from favorites'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove from favorites',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
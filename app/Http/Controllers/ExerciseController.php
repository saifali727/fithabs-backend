<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExerciseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Exercise::with('workout');

            // Filter by workout if provided
            if ($request->has('workout_id') && $request->input('workout_id')) {
                $query->where('workout_id', $request->input('workout_id'));
            }

            $exercises = $query->orderBy('order')->get();

            return response()->json([
                'status' => 'success',
                'data' => $exercises,
                'count' => $exercises->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve exercises',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $exercise = Exercise::with('workout')->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $exercise
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Log request size for debugging
            \Log::info('Exercise creation request size: ' . strlen(file_get_contents('php://input')));

            // Create validation data without file fields to avoid Laravel's file validation
            $validationData = $request->except(['video', 'image']);

            $validator = Validator::make($validationData, [
                'workout_id' => 'required|exists:workouts,id',
                'name' => 'required|string|max:255',
                'instructions' => 'nullable|string',
                'video_url' => 'nullable|string|url',
                'image_url' => 'nullable|string',
                'duration_seconds' => 'nullable|integer|min:0',
                'repetitions' => 'nullable|integer|min:0',
                'sets' => 'nullable|integer|min:0',
                'rest_seconds' => 'nullable|integer|min:0',
                'order' => 'integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->only([
                'workout_id',
                'name',
                'instructions',
                'duration_seconds',
                'repetitions',
                'sets',
                'rest_seconds',
                'order'
            ]);

            // Handle image upload with comprehensive validation
            if ($request->hasFile('image')) {
                try {
                    $imageFile = $request->file('image');

                    // Comprehensive file validation
                    $allowedImageTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
                    $allowedImageExtensions = ['jpeg', 'png', 'jpg', 'gif', 'webp'];

                    // Check file size
                    if ($imageFile->getSize() <= 0) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Image upload failed',
                            'error' => 'Image file is empty or corrupted'
                        ], 422);
                    }

                    // Check file size (10MB = 10485760 bytes)
                    $maxImageSize = 10485760;
                    if ($imageFile->getSize() > $maxImageSize) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Image upload failed',
                            'error' => 'Image file is too large. Maximum size: 10MB'
                        ], 422);
                    }

                    // Check MIME type
                    $mimeType = $imageFile->getMimeType();
                    if (!in_array($mimeType, $allowedImageTypes)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Image upload failed',
                            'error' => 'Invalid image file type. Allowed: ' . implode(', ', $allowedImageExtensions)
                        ], 422);
                    }

                    // Check file extension
                    $extension = strtolower($imageFile->getClientOriginalExtension());
                    if (!in_array($extension, $allowedImageExtensions)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Image upload failed',
                            'error' => 'Invalid image file extension. Allowed: ' . implode(', ', $allowedImageExtensions)
                        ], 422);
                    }

                    // Generate unique filename
                    $imageName = 'exercise_' . time() . '_' . Str::random(10) . '.' . $extension;
                    $imagePath = $imageFile->storeAs('exercises/images', $imageName, 'public');

                    if (!$imagePath) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Failed to store image file',
                            'error' => 'Storage error - check directory permissions'
                        ], 500);
                    }

                    $data['image_url'] = request()->getSchemeAndHttpHost() . Storage::url($imagePath);

                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Image upload failed',
                        'error' => $e->getMessage()
                    ], 422);
                }
            } elseif ($request->has('image_url')) {
                $data['image_url'] = $request->input('image_url');
            }

            // Handle video upload - accepts any video file type
            if ($request->hasFile('video')) {
                try {
                    $videoFile = $request->file('video');

                    // Check file size
                    if ($videoFile->getSize() <= 0) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Video upload failed',
                            'error' => 'Video file is empty or corrupted'
                        ], 422);
                    }

                    // Check file size (100MB = 104857600 bytes)
                    $maxVideoSize = 104857600;
                    if ($videoFile->getSize() > $maxVideoSize) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Video upload failed',
                            'error' => 'Video file is too large. Maximum size: 100MB'
                        ], 422);
                    }

                    // Generate unique filename using original extension
                    $extension = strtolower($videoFile->getClientOriginalExtension()) ?: 'mp4';
                    $videoName = 'exercise_' . time() . '_' . Str::random(10) . '.' . $extension;
                    $videoPath = $videoFile->storeAs('exercises/videos', $videoName, 'public');

                    if (!$videoPath) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Failed to store video file',
                            'error' => 'Storage error - check directory permissions'
                        ], 500);
                    }

                    $data['video_url'] = request()->getSchemeAndHttpHost() . Storage::url($videoPath);

                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Video upload failed',
                        'error' => $e->getMessage()
                    ], 422);
                }
            } elseif ($request->has('video_url')) {
                $data['video_url'] = $request->input('video_url');
            }

            $exercise = Exercise::create($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Exercise created successfully',
                'data' => $exercise
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $exercise = Exercise::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'workout_id' => 'sometimes|exists:workouts,id',
                'name' => 'sometimes|string|max:255',
                'instructions' => 'nullable|string',
                'video' => 'nullable|file|mimes:mp4,avi,mov,wmv,flv,webm', // Remove max size validation
                'image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp', // Remove max size validation
                'video_url' => 'nullable|string|url',
                'image_url' => 'nullable|string',
                'duration_seconds' => 'nullable|integer|min:0',
                'repetitions' => 'nullable|integer|min:0',
                'sets' => 'nullable|integer|min:0',
                'rest_seconds' => 'nullable|integer|min:0',
                'order' => 'sometimes|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->only([
                'workout_id',
                'name',
                'instructions',
                'duration_seconds',
                'repetitions',
                'sets',
                'rest_seconds',
                'order'
            ]);

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($exercise->image_url) {
                    $oldImagePath = str_replace('/storage/', '', $exercise->image_url);
                    Storage::disk('public')->delete($oldImagePath);
                }

                $imageFile = $request->file('image');
                $imageName = 'exercise_' . time() . '_' . Str::random(10) . '.' . $imageFile->getClientOriginalExtension();
                $imagePath = $imageFile->storeAs('exercises/images', $imageName, 'public');
                $data['image_url'] = request()->getSchemeAndHttpHost() . Storage::url($imagePath);
            } elseif ($request->has('image_url')) {
                $data['image_url'] = $request->input('image_url');
            }

            // Handle video upload
            if ($request->hasFile('video')) {
                // Delete old video if exists
                if ($exercise->video_url) {
                    $oldVideoPath = str_replace('/storage/', '', $exercise->video_url);
                    Storage::disk('public')->delete($oldVideoPath);
                }

                $videoFile = $request->file('video');
                $videoName = 'exercise_' . time() . '_' . Str::random(10) . '.' . $videoFile->getClientOriginalExtension();
                $videoPath = $videoFile->storeAs('exercises/videos', $videoName, 'public');
                $data['video_url'] = request()->getSchemeAndHttpHost() . Storage::url($videoPath);
            } elseif ($request->has('video_url')) {
                $data['video_url'] = $request->input('video_url');
            }

            $exercise->update($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Exercise updated successfully',
                'data' => $exercise
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $exercise = Exercise::findOrFail($id);
            $exercise->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Exercise deleted successfully'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get exercises for a specific workout
     *
     * @param int $workoutId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWorkoutExercises($workoutId)
    {
        try {
            $workout = Workout::findOrFail($workoutId);

            $exercises = Exercise::where('workout_id', $workoutId)
                ->orderBy('order')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'workout' => $workout,
                    'exercises' => $exercises,
                    'total_exercises' => $exercises->count(),
                    'total_sets' => $exercises->sum('sets'),
                    'estimated_duration' => $exercises->sum(function ($exercise) {
                        return ($exercise->duration_seconds ?? 0) + ($exercise->rest_seconds ?? 0);
                    })
                ]
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Workout not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve workout exercises',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the next exercise in a workout
     *
     * @param int $workoutId
     * @param int $currentExerciseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextExercise($workoutId, $currentExerciseId = null)
    {
        try {
            $query = Exercise::where('workout_id', $workoutId)->orderBy('order');

            if ($currentExerciseId) {
                $currentExercise = Exercise::findOrFail($currentExerciseId);
                $query->where('order', '>', $currentExercise->order);
            }

            $nextExercise = $query->first();

            if (!$nextExercise) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No more exercises in this workout',
                    'data' => null
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'data' => $nextExercise
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get next exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the previous exercise in a workout
     *
     * @param int $workoutId
     * @param int $currentExerciseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPreviousExercise($workoutId, $currentExerciseId)
    {
        try {
            $currentExercise = Exercise::findOrFail($currentExerciseId);

            $previousExercise = Exercise::where('workout_id', $workoutId)
                ->where('order', '<', $currentExercise->order)
                ->orderBy('order', 'desc')
                ->first();

            if (!$previousExercise) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No previous exercises in this workout',
                    'data' => null
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'data' => $previousExercise
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get previous exercise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update exercise progress for a user workout session
     *
     * @param Request $request
     * @param int $sessionId
     * @param int $exerciseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateExerciseProgress(Request $request, $sessionId, $exerciseId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sets_completed' => 'nullable|integer|min:0',
                'reps_completed' => 'nullable|integer|min:0',
                'duration_completed' => 'nullable|integer|min:0',
                'is_completed' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid data provided',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $session = $user->userWorkouts()->findOrFail($sessionId);
            $exercise = Exercise::findOrFail($exerciseId);

            // Update exercise progress in session
            $progress = $session->exercise_progress ?? [];
            $progress[$exerciseId] = [
                'sets_completed' => $request->input('sets_completed', 0),
                'reps_completed' => $request->input('reps_completed', 0),
                'duration_completed' => $request->input('duration_completed', 0),
                'is_completed' => $request->input('is_completed', false),
                'updated_at' => now()->toISOString()
            ];

            $session->update(['exercise_progress' => $progress]);

            return response()->json([
                'status' => 'success',
                'message' => 'Exercise progress updated',
                'data' => [
                    'session' => $session,
                    'exercise_progress' => $progress[$exerciseId]
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session or exercise not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update exercise progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simple file upload test - minimal endpoint
     */
    public function simpleUploadTest(Request $request)
    {
        try {
            $result = [
                'has_files' => $request->hasFile('test_file'),
                'all_files' => $request->allFiles(),
                'php_upload_max_filesize' => ini_get('upload_max_filesize'),
                'php_post_max_size' => ini_get('post_max_size'),
            ];

            if ($request->hasFile('test_file')) {
                $file = $request->file('test_file');
                $result['file_info'] = [
                    'is_valid' => $file->isValid(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'original_name' => $file->getClientOriginalName(),
                    'error' => $file->getErrorMessage(),
                    'error_code' => $file->getError(),
                ];

                if ($file->isValid()) {
                    // Try to store the file
                    $path = $file->store('test_uploads', 'public');
                    $result['stored_path'] = $path;
                    $result['storage_url'] = Storage::url($path);
                }
            }

            return response()->json([
                'status' => 'success',
                'result' => $result
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug file upload - temporary endpoint for testing
     */
    public function debugUpload(Request $request)
    {
        try {
            $debug = [
                'has_video' => $request->hasFile('video'),
                'has_image' => $request->hasFile('image'),
                'all_files' => $request->allFiles(),
                'php_upload_max_filesize' => ini_get('upload_max_filesize'),
                'php_post_max_size' => ini_get('post_max_size'),
                'php_max_file_uploads' => ini_get('max_file_uploads'),
                'php_max_execution_time' => ini_get('max_execution_time'),
                'php_memory_limit' => ini_get('memory_limit'),
                'upload_tmp_dir' => ini_get('upload_tmp_dir'),
                'temp_dir' => sys_get_temp_dir(),
                'request_size' => strlen(file_get_contents('php://input')),
            ];

            if ($request->hasFile('video')) {
                $videoFile = $request->file('video');
                $debug['video_info'] = [
                    'is_valid' => $videoFile->isValid(),
                    'size' => $videoFile->getSize(),
                    'mime_type' => $videoFile->getMimeType(),
                    'original_name' => $videoFile->getClientOriginalName(),
                    'extension' => $videoFile->getClientOriginalExtension(),
                    'error' => $videoFile->getErrorMessage(),
                    'error_code' => $videoFile->getError(),
                    'max_size_bytes' => $videoFile->getMaxFilesize(),
                ];
            }

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');
                $debug['image_info'] = [
                    'is_valid' => $imageFile->isValid(),
                    'size' => $imageFile->getSize(),
                    'mime_type' => $imageFile->getMimeType(),
                    'original_name' => $imageFile->getClientOriginalName(),
                    'extension' => $imageFile->getClientOriginalExtension(),
                    'error' => $imageFile->getErrorMessage(),
                    'error_code' => $imageFile->getError(),
                    'max_size_bytes' => $imageFile->getMaxFilesize(),
                ];
            }

            return response()->json([
                'status' => 'success',
                'debug' => $debug
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debug failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
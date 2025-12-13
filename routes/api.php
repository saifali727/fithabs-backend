<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PasswordResetTokenController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\CacheController;
use App\Http\Controllers\CacheLockController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobBatchController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\PersonalAccessTokenController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\TherapistController;
use App\Http\Controllers\DailyActivityController;
use App\Http\Controllers\WorkoutController;
use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\UserWorkoutController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealPlanRecipeController;
use App\Http\Controllers\UserMealPlanController;
use App\Http\Controllers\UserProgressController;
use App\Http\Controllers\EducationContentController;
use App\Http\Controllers\UserFavoriteController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AiChatMessageController;
use App\Http\Controllers\SearchLogController;
use App\Http\Controllers\UserSettingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserAchievementController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\UserPreferenceController;
use App\Http\Controllers\UserGoalController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CoachDashboardController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReviewController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Sanctum CSRF cookie route for token-based authentication
Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']);

// API version 1 routes
Route::prefix('v1')->group(function () {

    // Public routes (no authentication required)
    Route::get('/test', function () {
        return response()->json(['message' => 'API is working!']);
    });

    //  Admin user api routes
    Route::post('/admin-login', [AuthController::class, 'adminLogin']);


    // Authentication routes (public)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/login/google', [AuthController::class, 'googleLogin']);
    Route::post('/login/apple', [AuthController::class, 'appleLogin']);

    // Password reset token routes (public for reset functionality)
    Route::apiResource('password-reset-tokens', PasswordResetTokenController::class)->only(['store', 'show', 'destroy']);

    // Home screen route (public)
    Route::get('/home', [HomeController::class, 'index']);

    // Professional listings (public for browsing)
    Route::get('/coaches', [CoachController::class, 'index']);
    Route::get('/therapists', [TherapistController::class, 'index']);
    Route::get('/clinics', [ClinicController::class, 'index']);

    // Services (Public)
    Route::get('/coaches/{coach_id}/services', [ServiceController::class, 'index']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::get('/coaches/{coach_id}/reviews', [ReviewController::class, 'index']);



    // Authenticated routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Authentication routes
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Profile management routes (users can update their own profile)
        Route::get('/profile', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            return response()->json([
                'status' => 'success',
                'data' => $user
            ], 200);
        });

        // Basic profile information update
        Route::put('/profile/basic', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
                'age' => 'sometimes|integer|min:13|max:120',
                'dob' => 'nullable|date|before:today',
                'phone' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $data = $request->only(['name', 'email', 'age', 'dob', 'phone']);
                $user->update($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Basic profile updated successfully',
                    'data' => $user->fresh()
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update basic profile',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Password update
        Route::put('/profile/password', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'password' => 'required|string|min:8',
                'password_confirmation' => 'required|same:password',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $user->update([
                    'password' => \Illuminate\Support\Facades\Hash::make($request->password)
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Password updated successfully'
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update password',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Physical profile update
        Route::put('/profile/physical', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'gender' => 'nullable|in:male,female,other',
                'weight' => 'nullable|numeric|min:0',
                'weight_unit' => 'nullable|in:kg,lb',
                'height' => 'nullable|numeric|min:0',
                'height_unit' => 'nullable|in:cm,ft',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $data = $request->only(['gender', 'weight', 'weight_unit', 'height', 'height_unit']);
                $user->update($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Physical profile updated successfully',
                    'data' => $user->fresh()
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update physical profile',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Goals and preferences update
        Route::put('/profile/goals', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'goal' => 'nullable|in:lose_weight,gain_weight,maintain_weight,build_muscle',
                'activity_level' => 'nullable|in:sedentary,light,moderate,very_active',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $data = $request->only(['goal', 'activity_level']);
                $user->update($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Goals and preferences updated successfully',
                    'data' => $user->fresh()
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update goals and preferences',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Daily targets update
        Route::put('/profile/targets', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'daily_calorie_goal' => 'nullable|integer|min:0',
                'daily_steps_goal' => 'nullable|integer|min:0',
                'daily_water_goal' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $data = $request->only(['daily_calorie_goal', 'daily_steps_goal', 'daily_water_goal']);
                $user->update($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Daily targets updated successfully',
                    'data' => $user->fresh()
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update daily targets',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Full profile update (for backward compatibility)
        Route::put('/profile', function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
                'password' => 'sometimes|string|min:8',
                'age' => 'sometimes|integer|min:13|max:120',
                'gender' => 'nullable|in:male,female,other',
                'weight' => 'nullable|numeric|min:0',
                'weight_unit' => 'nullable|in:kg,lb',
                'height' => 'nullable|numeric|min:0',
                'height_unit' => 'nullable|in:cm,ft',
                'goal' => 'nullable|in:lose_weight,gain_weight,maintain_weight,build_muscle',
                'activity_level' => 'nullable|in:sedentary,light,moderate,very_active',
                'daily_calorie_goal' => 'nullable|integer|min:0',
                'daily_steps_goal' => 'nullable|integer|min:0',
                'daily_water_goal' => 'nullable|numeric|min:0',
                'dob' => 'nullable|date|before:today',
                'phone' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            try {
                $data = $request->only([
                    'name',
                    'email',
                    'age',
                    'gender',
                    'weight',
                    'weight_unit',
                    'height',
                    'height_unit',
                    'goal',
                    'activity_level',
                    'daily_calorie_goal',
                    'daily_steps_goal',
                    'daily_water_goal',
                    'dob',
                    'phone'
                ]);

                if ($request->has('password')) {
                    $data['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
                }

                $user->update($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Profile updated successfully',
                    'data' => $user->fresh()
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to update profile',
                    'error' => $e->getMessage()
                ], 500);
            }
        });

        // Content viewing routes (authenticated users can view)
        Route::get('workouts/filter', [WorkoutController::class, 'filter']);
        Route::get('workouts/difficulty/{difficulty}', [WorkoutController::class, 'getByDifficulty']);
        Route::post('workouts/{workout}/start', [WorkoutController::class, 'startWorkout']);
        Route::put('workout-sessions/{session}/complete', [WorkoutController::class, 'completeWorkout']);
        Route::apiResource('workouts', WorkoutController::class)->only(['index', 'show']);
        Route::get('recipes/search', [RecipeController::class, 'search']);
        Route::get('recipes/recipe-of-the-day', [RecipeController::class, 'recipeOfTheDay']);
        Route::get('recipes/recommendations', [RecipeController::class, 'recommendations']);
        Route::get('recipes/nutrition-screen', [RecipeController::class, 'nutritionScreen']);
        Route::apiResource('recipes', RecipeController::class)->only(['index', 'show']);
        Route::get('meal-plans/filter', [MealPlanController::class, 'filter']);
        Route::get('meal-plans/personalized', [MealPlanController::class, 'personalized']);
        Route::get('meal-plans/meal-type/{mealType}', [MealPlanController::class, 'getByMealType']);
        Route::apiResource('meal-plans', MealPlanController::class)->only(['index', 'show']);
        Route::get('education-contents/search', [EducationContentController::class, 'search']);
        Route::apiResource('education-contents', EducationContentController::class)->only(['index', 'show']);

        // Video routes (authenticated users can view)
        Route::apiResource('videos', VideoController::class)->only(['index', 'show']);
        Route::get('videos/{video}/stream', [VideoController::class, 'stream']);

        // Image routes (authenticated users can view)
        Route::apiResource('images', ImageController::class)->only(['index', 'show']);
        Route::get('images/{image}/serve', [ImageController::class, 'serve']);

        // User personal routes (users can only access their own data)
        Route::get('user-workouts/stats', [UserWorkoutController::class, 'getStats']);
        Route::get('user-workouts/active', [UserWorkoutController::class, 'getActiveSessions']);
        Route::get('user-workouts/history', [UserWorkoutController::class, 'getHistory']);
        Route::apiResource('user-workouts', UserWorkoutController::class);
        Route::apiResource('user-meal-plans', UserMealPlanController::class);
        Route::get('user-meal-plans/current', [UserMealPlanController::class, 'current']);
        Route::get('user-meal-plans/date/{date}', [UserMealPlanController::class, 'getByDate']);
        Route::apiResource('user-preferences', UserPreferenceController::class)->only(['show', 'store', 'update']);
        Route::apiResource('user-goals', UserGoalController::class)->only(['show', 'store', 'update', 'destroy']);
        Route::apiResource('user-progress', UserProgressController::class);

        // Daily Activity / Progress Tracker routes (specific routes must come before resource routes)
        Route::get('daily-activities/progress-summary', [DailyActivityController::class, 'getProgressSummary']);
        Route::post('daily-activities/update', [DailyActivityController::class, 'updateDailyActivity']);
        Route::get('daily-activities/date-range', [DailyActivityController::class, 'getDateRange']);
        Route::apiResource('daily-activities', DailyActivityController::class);

        Route::apiResource('user-favorites', UserFavoriteController::class)->only(['index', 'store', 'destroy']);
        Route::post('user-favorites/remove-by-item', [UserFavoriteController::class, 'removeByItem']);
        Route::apiResource('user-settings', UserSettingController::class);
        Route::apiResource('notifications', NotificationController::class);
        Route::apiResource('user-achievements', UserAchievementController::class);

        // Booking & Payments
        Route::post('bookings/intent', [PaymentController::class, 'createPaymentIntent']);
        Route::post('bookings/confirm', [PaymentController::class, 'confirmBooking']);
        Route::get('bookings', [PaymentController::class, 'index']);
        Route::put('bookings/{id}/cancel', [PaymentController::class, 'cancel']);

        // Reviews
        Route::post('reviews', [ReviewController::class, 'store']); // Create review


        // Chat routes with professional access
        Route::middleware(['professional.access'])->group(function () {
            Route::apiResource('chats', ChatController::class);
            Route::apiResource('chat-messages', ChatMessageController::class)->middleware('throttle:100,1');
            // Professionals manage their services
            Route::get('services/me', [ServiceController::class, 'myServices']);
            Route::apiResource('services', ServiceController::class)->except(['index', 'show']);

            // Coach Dashboard & Analytics
            Route::get('coach/dashboard/stats', [CoachDashboardController::class, 'getStats']);
            Route::get('coach/clients', [CoachDashboardController::class, 'getClients']);
            Route::get('coach/invoices', [CoachDashboardController::class, 'getInvoices']);
        });

        // AI Chat routes (authenticated)
        Route::get('/ai-chats', [AiChatController::class, 'index']);
        Route::post('/ai-chats', [AiChatController::class, 'store']);
        Route::get('/ai-chats/{id}', [AiChatController::class, 'show']);
        Route::post('/ai-chats/{id}/send', [AiChatController::class, 'sendMessage']);
        Route::delete('/ai-chats/{id}', [AiChatController::class, 'destroy']);
        Route::apiResource('ai-chat-messages', AiChatMessageController::class)->middleware('throttle:100,1');

        // Search log routes with rate limiting
        Route::apiResource('search-logs', SearchLogController::class)->middleware('throttle:60,1');
        Route::get('search-logs/recent', [SearchLogController::class, 'recentSearches']);

        // System-related routes
        Route::apiResource('sessions', SessionController::class);
        Route::apiResource('cache', CacheController::class);
        Route::apiResource('cache-locks', CacheLockController::class);
        Route::apiResource('jobs', JobController::class);
        Route::apiResource('job-batches', JobBatchController::class);
        Route::apiResource('failed-jobs', FailedJobController::class);
        Route::apiResource('personal-access-tokens', PersonalAccessTokenController::class);
    });

    // Admin routes (restricted to admin users only)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/admin-check', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return response()->json(['message' => 'Admin access granted']);
        });

        // Dashboard route (admin only)
        Route::get('/dashboard', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\DashboardController::class)->index();
        });

        Route::get('/admin-users', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\AdminUserController::class)->index($request);
        });

        Route::get('/users', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\UserController::class)->index($request);
        });

        // Content management routes (admin users can create, update, delete)
        Route::post('/workouts', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\WorkoutController::class)->store($request);
        });

        Route::put('/workouts/{workout}', function (\Illuminate\Http\Request $request, $workout) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\WorkoutController::class)->update($request, $workout);
        });

        Route::delete('/workouts/{workout}', function (\Illuminate\Http\Request $request, $workout) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\WorkoutController::class)->destroy($workout);
        });

        Route::post('/recipes', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\RecipeController::class)->store($request);
        });

        Route::put('/recipes/{recipe}', function (\Illuminate\Http\Request $request, $recipe) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\RecipeController::class)->update($request, $recipe);
        });

        Route::delete('/recipes/{recipe}', function (\Illuminate\Http\Request $request, $recipe) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\RecipeController::class)->destroy($recipe);
        });

        // Coach management routes (admin only)
        Route::get('/admin/coaches', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\CoachController::class)->adminIndex($request);
        });

        Route::post('/admin/coaches', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\CoachController::class)->store($request);
        });

        Route::put('/admin/coaches/{coach}', function (\Illuminate\Http\Request $request, $coach) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\CoachController::class)->update($request, $coach);
        });

        Route::delete('/admin/coaches/{coach}', function (\Illuminate\Http\Request $request, $coach) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\CoachController::class)->destroy($coach);
        });

        // Clinic management routes (admin only)
        Route::get('/admin/clinics', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ClinicController::class)->adminIndex($request);
        });

        Route::post('/admin/clinics', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ClinicController::class)->store($request);
        });

        Route::put('/admin/clinics/{clinic}', function (\Illuminate\Http\Request $request, $clinic) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ClinicController::class)->update($request, $clinic);
        });

        Route::delete('/admin/clinics/{clinic}', function (\Illuminate\Http\Request $request, $clinic) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ClinicController::class)->destroy($clinic);
        });

        Route::post('/meal-plans', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\MealPlanController::class)->store($request);
        });

        Route::put('/meal-plans/{meal_plan}', function (\Illuminate\Http\Request $request, $meal_plan) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\MealPlanController::class)->update($request, $meal_plan);
        });

        Route::delete('/meal-plans/{meal_plan}', function (\Illuminate\Http\Request $request, $meal_plan) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\MealPlanController::class)->destroy($meal_plan);
        });

        Route::post('/education-contents', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\EducationContentController::class)->store($request);
        });

        Route::put('/education-contents/{education_content}', function (\Illuminate\Http\Request $request, $education_content) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\EducationContentController::class)->update($request, $education_content);
        });

        Route::delete('/education-contents/{education_content}', function (\Illuminate\Http\Request $request, $education_content) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\EducationContentController::class)->destroy($education_content);
        });

        // Video management routes (admin only)
        Route::post('/videos', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\VideoController::class)->store($request);
        });

        Route::put('/videos/{video}', function (\Illuminate\Http\Request $request, $video) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\VideoController::class)->update($request, $video);
        });

        Route::delete('/videos/{video}', function (\Illuminate\Http\Request $request, $video) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\VideoController::class)->destroy($video);
        });

        // Image management routes (admin only)
        Route::post('/images', function (\Illuminate\Http\Request $request) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ImageController::class)->store($request);
        });

        Route::put('/images/{image}', function (\Illuminate\Http\Request $request, $image) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ImageController::class)->update($request, $image);
        });

        Route::delete('/images/{image}', function (\Illuminate\Http\Request $request, $image) {
            if (!$request->user() || !($request->user() instanceof \App\Models\AdminUser)) {
                return response()->json(['error' => 'Admin access required'], 403);
            }
            return app(\App\Http\Controllers\ImageController::class)->destroy($image);
        });
    });

    // Content creation routes (restricted to editor or super_admin)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::apiResource('exercises', ExerciseController::class);
        Route::get('workouts/{workout}/exercises', [ExerciseController::class, 'getWorkoutExercises']);
        Route::post('exercises/debug-upload', [ExerciseController::class, 'debugUpload']);
        Route::post('exercises/simple-upload-test', [ExerciseController::class, 'simpleUploadTest']);
        Route::get('workouts/{workout}/exercises/next/{exercise?}', [ExerciseController::class, 'getNextExercise']);
        Route::get('workouts/{workout}/exercises/previous/{exercise}', [ExerciseController::class, 'getPreviousExercise']);
        Route::put('workout-sessions/{session}/exercises/{exercise}/progress', [ExerciseController::class, 'updateExerciseProgress']);
        Route::apiResource('meal-plan-recipes', MealPlanRecipeController::class);
    });

    // Professional routes (restricted to respective professional types)
    Route::middleware(['auth:sanctum', 'validate.json'])->group(function () {
        // These routes require authentication and are for professional management
        // Note: Coach and Clinic routes are handled in admin section above

        Route::post('therapists', [TherapistController::class, 'store']);
        Route::put('therapists/{therapist}', [TherapistController::class, 'update']);
        Route::delete('therapists/{therapist}', [TherapistController::class, 'destroy']);
    });
});
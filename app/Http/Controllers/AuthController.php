<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AdminUser;
use App\Models\PasswordResetToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    /**
     * Log in a user and issue a token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 200);
    }

    public function adminLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Try to authenticate as AdminUser first
        $adminUser = AdminUser::where('email', $request->email)->first();
        if ($adminUser && Hash::check($request->password, $adminUser->password)) {
            $token = $adminUser->createToken('admin_token')->plainTextToken;
            return response()->json([
                'user' => $adminUser, 
                'token' => $token,
                'role' => $adminUser->role
            ], 200);
        }

        // If not admin, try to authenticate as a Coach (User with coach role)
        // We look up the User table because that's where auth credentials are now stored for coaches
        $user = User::where('email', $request->email)->first();
        
        if ($user && Hash::check($request->password, $user->password)) {
            // Check if user has coach role
            if ($user->role === 'coach') {
                // Ensure the coach profile is active
                $coach = $user->coach;
                if (!$coach || !$coach->is_active) {
                    return response()->json(['error' => 'Account is inactive'], 403);
                }

                $token = $user->createToken('coach_token')->plainTextToken;
                return response()->json([
                    'user' => $user,
                    'coach_profile' => $coach,
                    'token' => $token,
                    'role' => 'coach'
                ], 200);
            }
        }

        // Backward compatibility: Check old Coach table directly (if they haven't been migrated to User table yet)
        $legacyCoach = \App\Models\Coach::where('email', $request->email)->first();
        if ($legacyCoach && !$legacyCoach->user_id && Hash::check($request->password, $legacyCoach->password)) {
             if (!$legacyCoach->is_active) {
                return response()->json(['error' => 'Account is inactive'], 403);
            }
            // We can't issue a standard Sanctum token easily without a User model, 
            // but if you have a custom auth guard for coaches you would use it here.
            // For now, assuming we want to move everyone to User table.
            return response()->json(['error' => 'Please contact support to migrate your account.'], 403);
        }

        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    /**
     * Initiate a password reset.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $token = str_random(60);
        PasswordResetToken::create([
            'email' => $request->email,
            'token' => $token,
            'created_at' => now(),
        ]);

        // Send email (example, adjust with your mail setup)
        Mail::raw("Reset your password: " . url('/api/v1/reset-password?token=' . $token), function ($message) use ($request) {
            $message->to($request->email)->subject('Password Reset Request');
        });

        return response()->json(['message' => 'Password reset link sent'], 200);
    }

    /**
     * Reset password using a token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $reset = PasswordResetToken::where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json(['error' => 'Invalid token'], 422);
        }

        $user = User::where('email', $request->email)->first();
        $user->update(['password' => Hash::make($request->password)]);

        $reset->delete();

        return response()->json(['message' => 'Password reset successfully'], 200);
    }

    /**
     * Get the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        return response()->json($request->user(), 200);
    }

    /**
     * Log out the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out'], 200);
    }

    /**
     * Handle Google OAuth login.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->token);
            $user = User::firstOrCreate(
                ['email' => $googleUser->email],
                ['name' => $googleUser->name, 'password' => Hash::make(str_random(16))]
            );

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json(['user' => $user, 'token' => $token], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Google login failed'], 401);
        }
    }

    /**
     * Handle Apple OAuth login.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function appleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $appleUser = Socialite::driver('apple')->stateless()->userFromToken($request->token);
            $user = User::firstOrCreate(
                ['email' => $appleUser->email],
                ['name' => $appleUser->name ?? 'Apple User', 'password' => Hash::make(str_random(16))]
            );

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json(['user' => $user, 'token' => $token], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Apple login failed'], 401);
        }
    }
}
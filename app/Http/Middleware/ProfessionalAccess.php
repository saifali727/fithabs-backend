<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfessionalAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Allow if user is an AdminUser (from admin_users table)
        if ($request->user() instanceof \App\Models\AdminUser) {
            return $next($request);
        }

        // Allow if user is a standard User with professional role
        if ($request->user() instanceof \App\Models\User) {
            if (in_array($request->user()->role, ['coach', 'therapist'])) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Unauthorized. Professional access required.'], 403);
    }
}

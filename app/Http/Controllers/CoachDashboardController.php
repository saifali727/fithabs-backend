<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CoachDashboardController extends Controller
{
    /**
     * Get high-level stats for the coach dashboard.
     */
    public function getStats(Request $request)
    {
        $user = $request->user();
        $coach = null;

        if ($user instanceof \App\Models\Coach) {
            $coach = $user;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $coach = $user->coach;
        }

        // Ensure user is allowed
        if (!$coach) {
            return response()->json(['error' => 'Unauthorized - Coach profile not found'], 403);
        }

        $bookings = $coach->bookings();

        // Financials
        $totalEarnings = $coach->bookings()
            ->where('payment_status', 'paid')
            ->sum('amount');

        // Booking Counts
        $activeBookingsCount = $coach->bookings()
            ->where('status', 'confirmed')
            ->where('booked_for', '>=', Carbon::now())
            ->count();

        $completedBookingsCount = $coach->bookings()
            ->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhere(function ($sub) {
                        $sub->where('status', 'confirmed')
                            ->where('booked_for', '<', Carbon::now());
                    });
            })
            ->count();

        // Unique Clients
        $totalClients = $coach->bookings()->distinct('user_id')->count('user_id');

        return response()->json([
            'total_earnings' => $totalEarnings,
            'active_bookings' => $activeBookingsCount,
            'completed_bookings' => $completedBookingsCount,
            'total_unique_clients' => $totalClients
        ]);
    }

    /**
     * Get list of clients with their status relative to this coach.
     */
    public function getClients(Request $request)
    {
        $user = $request->user();
        $coach = null;

        if ($user instanceof \App\Models\Coach) {
            $coach = $user;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $coach = $user->coach;
        }

        if (!$coach) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get unique users who have booked this coach
        // We'll group by user_id to aggregate stats per user
        $clients = Booking::with('user')
            ->where('coach_id', $coach->id)
            ->select(
                'user_id',
                DB::raw('count(*) as total_bookings'),
                DB::raw('sum(amount) as total_spent'),
                DB::raw('max(booked_for) as last_booking_date')
            )
            ->groupBy('user_id')
            ->get()
            ->map(function ($item) {
                $lastBooking = Carbon::parse($item->last_booking_date);
                $isActive = $lastBooking->isFuture(); // Simple logic: if they have a future booking, they are "active"
    
                return [
                    'user' => $item->user,
                    'total_bookings' => $item->total_bookings,
                    'total_spent' => $item->total_spent,
                    'last_booking_date' => $item->last_booking_date,
                    'is_active' => $isActive,
                    'status' => $isActive ? 'Active' : 'Past'
                ];
            });

        return response()->json(['data' => $clients]);
    }

    /**
     * Get list of successful transactions (Invoices).
     */
    public function getInvoices(Request $request)
    {
        $user = $request->user();
        $coach = null;

        if ($user instanceof \App\Models\Coach) {
            $coach = $user;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $coach = $user->coach;
        }

        if (!$coach) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $invoices = $coach->bookings()
            ->with(['user', 'service'])
            ->where('payment_status', 'paid')
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->through(function ($booking) {
                return [
                    'invoice_id' => 'INV-' . str_pad($booking->id, 8, '0', STR_PAD_LEFT),
                    'transaction_id' => $booking->stripe_payment_intent_id,
                    'date' => $booking->created_at->format('Y-m-d H:i:s'),
                    'client_name' => $booking->user->name,
                    'client_email' => $booking->user->email,
                    'service_title' => $booking->service->title,
                    'amount' => $booking->amount,
                    'status' => 'Paid',
                    'download_url' => url("/api/v1/invoices/{$booking->id}/download") // Future feature
                ];
            });

        return response()->json($invoices);
    }
}

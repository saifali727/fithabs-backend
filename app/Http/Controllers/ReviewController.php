<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Coach;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    /**
     * Display a listing of reviews for a coach.
     */
    public function index(Request $request, $coach_id)
    {
        $reviews = Review::where('coach_id', $coach_id)
            ->where('is_public', true)
            ->with('user:id,name') // Only send minimal user info
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $average = Review::where('coach_id', $coach_id)->avg('rating');

        return response()->json([
            'average_rating' => round($average, 1),
            'reviews' => $reviews
        ]);
    }

    /**
     * Store a newly created review.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'coach_id' => 'required|exists:coaches,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'booking_id' => 'nullable|exists:bookings,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verification: User must have booked this coach at least once to review
        // Or if booking_id is provided, verify ownership
        if ($request->booking_id) {
            $booking = Booking::find($request->booking_id);
            if ($booking->user_id !== $user->id) {
                return response()->json(['error' => 'You cannot review a booking that is not yours.'], 403);
            }
        } else {
            // General check
            $hasBooking = Booking::where('user_id', $user->id)
                ->where('coach_id', $request->coach_id)
                // ->where('status', 'completed') // Optional: strict verified purchase
                ->exists();

            if (!$hasBooking) {
                // Allow them to review? Or block?
                // "User can give feedback" - usually implies experience.
                // Let's soft block or warn, but strict is safer for "verified"
                // For now, let's allow but maybe flag as unverified if we had that column.
                // Or just enforce it:
                return response()->json(['error' => 'You must have a booking with this coach to leave a review.'], 403);
            }
        }

        $review = Review::create([
            'user_id' => $user->id,
            'coach_id' => $request->coach_id,
            'booking_id' => $request->booking_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'is_public' => true
        ]);

        return response()->json([
            'message' => 'Review submitted successfully',
            'data' => $review
        ], 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function createPaymentIntent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_id' => 'required|exists:services,id',
            'booked_for' => 'required|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = Service::findOrFail($request->service_id);
        $user = $request->user();

        // Calculate timings
        $startTime = \Carbon\Carbon::parse($request->booked_for);
        $endTime = $startTime->copy()->addMinutes($service->duration_minutes);

        // Check for conflicts
        // A conflict exists if (StartA < EndB) and (EndA > StartB)
        $conflicts = Booking::where('coach_id', $service->coach_id)
            ->where('status', '!=', 'cancelled')
            ->where('payment_status', '!=', 'failed') // Don't block for failed payments? Or maybe we should if they are pending? 
            // Let's block pending and confirmed/paid.
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where('booked_for', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            })
            ->exists();

        if ($conflicts) {
            return response()->json(['error' => 'This time slot is already booked. Please choose another time.'], 409);
        }

        // Calculate amount in cents
        $amount = (int) ($service->price * 100);

        // Ensure STRIPE_SECRET is set in .env
        $stripeSecret = env('STRIPE_SECRET');
        if (!$stripeSecret) {
            return response()->json(['error' => 'Server configuration error: Stripe key missing'], 500);
        }

        Stripe::setApiKey($stripeSecret);

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'usd',
                'metadata' => [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'coach_id' => $service->coach_id,
                    'booked_for' => $startTime->toIso8601String(),
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            // Create Booking
            $booking = Booking::create([
                'user_id' => $user->id,
                'coach_id' => $service->coach_id,
                'service_id' => $service->id,
                'status' => 'pending',
                'payment_status' => 'pending',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount' => $service->price,
                'booked_for' => $startTime,
                'end_time' => $endTime,
            ]);

            return response()->json([
                'clientSecret' => $paymentIntent->client_secret,
                'booking_id' => $booking->id,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function confirmBooking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_intent_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $booking = Booking::where('stripe_payment_intent_id', $request->payment_intent_id)->first();
        
        if (!$booking) {
             return response()->json([
                'error' => 'Booking not found for the provided Payment Intent ID.',
                'details' => 'Please ensure the booking step was completed successfully.'
            ], 404);
        }

        $stripeSecret = env('STRIPE_SECRET');
        if (!$stripeSecret) {
            return response()->json(['error' => 'Server configuration error: Stripe key missing'], 500);
        }

        Stripe::setApiKey($stripeSecret);

        try {
            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);

            if ($paymentIntent->status == 'succeeded') {
                $booking->update([
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                ]);

                return response()->json(['status' => 'success', 'booking' => $booking]);
            } else {
                return response()->json(['status' => 'failed', 'message' => 'Payment not succeeded yet', 'stripe_status' => $paymentIntent->status], 400);
            }

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        
        // Determine role configuration
        $isCoach = false;
        
        if ($user instanceof \App\Models\Coach) {
            $isCoach = true;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $isCoach = true;
            // The user object is a User, but we might need the Coach ID for querying
            // However, the relationship is user->coach, so $user->coach->id would be the coach_id
            $coachProfile = $user->coach;
        }

        if ($isCoach) {
             if (isset($coachProfile)) {
                 $query = Booking::where('coach_id', $coachProfile->id);
             } else {
                 $query = $user->bookings(); // This works if user is instance of Coach model
             }
             $query->with(['user', 'service']);
        } else {
            // Client
            $query = $user->bookings()->with(['coach', 'service']);
        }

        $bookings = $query->orderBy('created_at', 'desc')->paginate(10);
        return response()->json($bookings);
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $booking = Booking::findOrFail($id);

        // Authorization: User who made it OR Coach receiving it can cancel

        // Authorization: User who made it OR Coach receiving it can cancel
        $isAuthorized = false;

        if ($user instanceof \App\Models\User) {
            // Check if user is the client
            if ($booking->user_id === $user->id) {
                $isAuthorized = true;
            } 
            // Check if user is the coach (via role)
            elseif ($user->role === 'coach' && $user->coach && $booking->coach_id === $user->coach->id) {
                $isAuthorized = true;
            }
        } elseif ($user instanceof \App\Models\Coach) {
            // Legacy Coach model check
            if ($booking->coach_id === $user->id) {
                $isAuthorized = true;
            }
        }

        if (!$isAuthorized) {
            return response()->json(['error' => 'Unauthorized actions'], 403);
        }

        if ($booking->status === 'cancelled') {
            return response()->json(['message' => 'Booking is already cancelled'], 400);
        }

        // Logic for refund could go here (e.g., Stripe Refund API)

        $booking->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Booking cancelled successfully', 'data' => $booking]);
    }
}

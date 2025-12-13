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

        $booking = Booking::where('stripe_payment_intent_id', $request->payment_intent_id)->firstOrFail();

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
        $query = $user->bookings();

        // If user is a typical User, show coach details.
        // If user is a Coach, show user (client) details.
        if ($user instanceof \App\Models\User) {
            $query->with(['coach', 'service']);
        } elseif ($user instanceof \App\Models\Coach) {
            $query->with(['user', 'service']);
        } else {
            // Fallback for other types
            $query->with(['coach', 'service']);
        }

        $bookings = $query->orderBy('created_at', 'desc')->paginate(10);
        return response()->json($bookings);
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $booking = Booking::findOrFail($id);

        // Authorization: User who made it OR Coach receiving it can cancel

        $isAuthorized = false;
        if ($user instanceof \App\Models\User && $booking->user_id === $user->id) {
            $isAuthorized = true;
        } elseif ($user instanceof \App\Models\Coach && $booking->coach_id === $user->id) {
            $isAuthorized = true;
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

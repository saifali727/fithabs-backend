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
            'booked_for' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = Service::findOrFail($request->service_id);
        $user = $request->user();

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
                'booked_for' => $request->booked_for,
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
        $bookings = $request->user()->bookings()->with(['coach', 'service'])->orderBy('created_at', 'desc')->paginate(10);
        return response()->json($bookings);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Coach;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    /**
     * Display a listing of the services for a specific coach.
     */
    public function index(Request $request)
    {
        if ($request->has('coach_id')) {
            $services = Service::where('coach_id', $request->coach_id)
                ->where('is_active', true)
                ->get();
            return response()->json(['data' => $services]);
        }

        // Return all active services if no coach specified? Or error?
        // Let's return all for now or paginated
        $services = Service::where('is_active', true)->paginate(20);
        return response()->json($services);
    }

    /**
     * Show the specified service.
     */
    public function show($id)
    {
        $service = Service::with('coach')->findOrFail($id);
        return response()->json(['data' => $service]);
    }

    /**
     * Store a newly created service.
     */
    public function store(Request $request)
    {
        // Assuming the authenticated user is the coach or admin
        // For now, let's assume we pass coach_id or it's implied from auth user if they are a coach
        // But since Auth logic for Coach vs User varies, I'll check if user has coach profile or pass strictly.

        $validator = Validator::make($request->all(), [
            'coach_id' => 'required|exists:coaches,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = Service::create($request->all());

        return response()->json([
            'message' => 'Service created successfully',
            'data' => $service
        ], 201);
    }

    /**
     * Update the specified service.
     */
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'duration_minutes' => 'sometimes|integer|min:1',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service->update($request->all());

        return response()->json([
            'message' => 'Service updated successfully',
            'data' => $service
        ]);
    }

    /**
     * Remove the specified service.
     */
    public function destroy($id)
    {
        $service = Service::findOrFail($id);
        $service->delete();

        return response()->json(['message' => 'Service deleted successfully']);
    }
}

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
     * Display a listing of services for the authenticated coach.
     */
    public function myServices(Request $request)
    {
        $user = $request->user();
        $coach = null;

        if ($user instanceof \App\Models\Coach) {
            $coach = $user;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $coach = $user->coach;
        }

        if (!$coach) {
            return response()->json(['error' => 'Unauthorized. Only coaches can access this.'], 403);
        }

        $services = $coach->services()->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $services]);
    }

    /**
     * Store a newly created service.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $coach = null;

        if ($user instanceof \App\Models\Coach) {
            $coach = $user;
        } elseif ($user instanceof \App\Models\User && $user->role === 'coach') {
            $coach = $user->coach;
        }

        $isCoach = !is_null($coach);
        
        $validator = Validator::make($request->all(), [
            'coach_id' => $isCoach ? 'nullable|exists:coaches,id' : 'required|exists:coaches,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->all();

        // If authenticated user is a coach and no coach_id provided, default to self
        if (!isset($data['coach_id']) && $isCoach) {
            $data['coach_id'] = $coach->id;
        }

        $service = Service::create($data);

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

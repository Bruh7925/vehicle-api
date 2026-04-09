<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class VehicleController extends Controller
{
    public function search(string $plateNumber): JsonResponse
    {
        $vehicle = Vehicle::where('plateNumber', $plateNumber)->first();

        if (! $vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $vehicle,
        ], 200);
    }
}

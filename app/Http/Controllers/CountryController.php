<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Services\CountryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CountryController extends Controller
{
    protected CountryService $countryService;

    public function __construct(CountryService $countryService)
    {
        $this->countryService = $countryService;

        if (!$countryService) {
            return response()->json([
                'status' => 'error',
                'message' => 'Countries List not Found',
            ]);
        }
    }

    public function refresh()
    {

    Log::info('Starting country refresh process...');

    $result = $this->countryService->refreshCountries();

    // Log the full result for debugging (you can limit details in production)
    Log::info('Country refresh result:', $result);

    if (isset($result['error'])) {
        Log::error('Country refresh failed', [
            'error' => $result['error'],
            'details' => $result['details'] ?? null,
        ]);

        if ($result['error'] === 'Validation failed') {
            return response()->json($result, 400);
        } elseif ($result['error'] === 'External data source unavailable') {
            return response()->json($result, 503);
        } else {
            return response()->json($result, 500);
        }
    }

    Log::info('Countries refreshed successfully', [
        'last_refreshed_at' => $result['last_refreshed_at'] ?? now(),
    ]);

    return response()->json([
        'message' => 'Countries refreshed successfully',
        'last_refreshed_at' => $result['last_refreshed_at'] ?? now(),
    ]);
}

    public function index(Request $request)
    {
        $query = Country::query();

        if (!$query) {
            return response()->json([
                'error' => '',
                'message' => 'Connection Failed'
            ], 409);
        }

        if ($request->has('region')) {
            $query->where('region', $request->region);
        }
        if ($request->has('currency')) {
            $query->where('currency_code', $request->currency);
        }
        if ($request->has('sort')) {
            $sortField = 'estimated_gdp';
            $sortDirection = 'desc';
            if (in_array($request->sort, ['gdp_desc', 'gdp_asc', 'name_desc', 'name_asc', 'population_desc', 'population_asc'])) {
                $parts = explode('_', $request->sort);
                $sortField = str_replace(['gdp', 'name', 'population'], ['estimated_gdp', 'name', 'population'], $parts[0]);
                $sortDirection = $parts[1];
            }
            $query->orderBy($sortField, $sortDirection);
        }

        $countries = $query->get();
         if ($countries->isEmpty()) {
            return response()->json(['error' => 'Country List Not Found'], 404);
        }
        return response()->json($countries);
    }

public function show(string $name)
{
    $country = Country::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

    if (!$country) {
        Log::warning('Country not found', ['name' => $name]);
        return response()->json(['error' => 'Country not found'], 404);
    }

    return response()->json($country);
}

    public function destroy(string $name)
    {
        $country = Country::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if (!$country) {
            return response()->json(['error' => 'Could not fetch Countries Data to Delete'], 409);
        }
        $country->delete();
        return response()->json(['message' => 'Country deleted successfully'], 201);
    }

    public function status()
    {
        $totalCountries = Country::count();
        $lastRefreshedAt = Country::max('last_refreshed_at');
        if ($totalCountries === 0 || $lastRefreshedAt === null) {
            return response()->json(['error' => 'Status endpoint failed'], 404);
        } else {
            return response()->json([
            'total_countries' => $totalCountries,
            'last_refreshed_at' => $lastRefreshedAt,
        ]);
        }
    }

    public function image()
    {

// if (app()->environment('production')) {
//             $path = storage_path('app/private/cache/summary.png');
// }
        $path = Storage::disk('persistent')->path('cache/summary.png');
        if (!file_exists($path)) {
            return response()->json(['error' => 'Summary image not found'], 404);
        }
        return response()->file($path);
    }
}
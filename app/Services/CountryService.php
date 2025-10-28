<?php

namespace App\Services;

use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CountryService
{
    protected string $countriesApi;
    protected string $exchangeApi;

    public function __construct()
    {
        $this->countriesApi = env('COUNTRIES_API_URL', 'https://restcountries.com/v2/all?fields=name,capital,region,population,flag,currencies');
        $this->exchangeApi = env('EXCHANGE_API_URL', 'https://open.er-api.com/v6/latest/USD');
    }

    public function refreshCountries()
    {
        try {
            $countriesResponse = Http::timeout(10)->get($this->countriesApi);
            if (!$countriesResponse->ok()) {
                return [
                    'error' => 'External data source unavailable',
                    'details' => 'Could not fetch data from Countries API'
                ];
            }
            $countriesData = $countriesResponse->json();

            $exchangeResponse = Http::timeout(10)->get($this->exchangeApi);
            if (!$exchangeResponse->ok()) {
                return [
                    'error' => 'External data source unavailable',
                    'details' => 'Could not fetch data from Exchange Rates API'
                ];
            }
            $exchangeRates = $exchangeResponse->json()['rates'] ?? [];

            $lastRefreshedAt = now();
            DB::beginTransaction();

            $countriesData = collect($countriesData)->chunk(50);
            foreach ($countriesData as $chunk) {
                foreach ($chunk as $country) {
                    $validator = Validator::make($country, [
                        'name' => 'required|string',
                        'population' => 'required|integer|min:0',
                    ]);

                    if ($validator->fails()) {
                        DB::rollBack();
                        return [
                            'error' => 'Validation failed',
                            'details' => $validator->errors()->toArray()
                        ];
                    }

                    $currencyCode = null;
                    if (!empty($country['currencies']) && is_array($country['currencies'])) {
                        $currencyCode = $country['currencies'][0]['code'] ?? null;
                        if ($currencyCode && !is_string($currencyCode)) {
                            $currencyCode = null;
                        }
                    }

                    // Additional validation for currency_code if currencies present
                    if ($currencyCode === null && !empty($country['currencies'])) {
                        DB::rollBack();
                        return [
                            'error' => 'Validation failed',
                            'details' => ['currency_code' => 'is required']
                        ];
                    }

                    $exchangeRate = null;
                    $estimatedGdp = null;
                    if (empty($country['currencies']) || $currencyCode === null) {
                        $estimatedGdp = 0;
                    } elseif (!isset($exchangeRates[$currencyCode])) {
                        $estimatedGdp = null;
                    } else {
                        $exchangeRate = $exchangeRates[$currencyCode];
                        $randomMultiplier = rand(1000, 2000);
                        $estimatedGdp = ($country['population'] * $randomMultiplier) / $exchangeRate;
                    }

                    // Case-insensitive find or create
                    $existingCountry = Country::whereRaw('LOWER(name) = ?', [strtolower($country['name'])])->first();

                    $data = [
                        'capital' => $country['capital'] ?? null,
                        'region' => $country['region'] ?? null,
                        'population' => $country['population'],
                        'currency_code' => $currencyCode,
                        'exchange_rate' => $exchangeRate,
                        'estimated_gdp' => $estimatedGdp,
                        'flag_url' => $country['flag'] ?? null,
                        'last_refreshed_at' => $lastRefreshedAt,
                    ];

                    if ($existingCountry) {
                        $existingCountry->update($data);
                    } else {
                        $data['name'] = $country['name'];
                        Country::create($data);
                    }
                }
            }

            DB::commit();
            $this->generateSummaryImage($lastRefreshedAt);
            return ['success' => true, 'last_refreshed_at' => $lastRefreshedAt];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['error' => 'Internal server error', 'details' => $e->getMessage()];
        }
    }

    protected function generateSummaryImage($lastRefreshedAt)
    {
        try {
            $totalCountries = Country::count();
            $topCountries = Country::orderBy('estimated_gdp', 'desc')->limit(5)->get();

            if (!function_exists('imagecreatetruecolor')) {
                throw new \Exception('GD extension is not enabled');
            }

            $img = imagecreatetruecolor(600, 400);
            if (!$img) {
                throw new \Exception('Failed to create image');
            }

            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 0, 0, 0);
            imagefill($img, 0, 0, $white);

            if (!function_exists('imagettftext')) {
                throw new \Exception('FreeType support is not enabled in GD');
            }

            $fontPath = public_path('fonts/Roboto-Regular.ttf');
            if (!file_exists($fontPath)) {
                throw new \Exception('Font file not found at: ' . $fontPath);
            }

            imagettftext($img, 22, 0, 150, 30, $black, $fontPath, "Country Currency API Summary");
            imagettftext($img, 16, 0, 20, 80, $black, $fontPath, "Total countries: $totalCountries");
            imagettftext($img, 16, 0, 20, 120, $black, $fontPath, "Top 5 Countries by Estimated GDP:");

            $y = 160;
            foreach ($topCountries as $country) {
                $text = "{$country->name}: " . number_format($country->estimated_gdp, 2);
                imagettftext($img, 14, 0, 40, $y, $black, $fontPath, $text);
                $y += 30;
            }

            imagettftext($img, 14, 0, 20, 350, $black, $fontPath, "Last refreshed at: " . $lastRefreshedAt->toDateTimeString());

            // Creating a cache to store images
if (app()->environment('production')) {
    // Use a persistent driver
    $disk = Storage::disk('persistent');// or whatever your env uses
            $imagePath = storage_path('app/private/cache/summary.png');

} else {
    $disk = Storage::disk('local');
            $imagePath = storage_path('app/private/cache/summary.png');

}

            if (!$disk->exists('cache')) {
                $disk->makeDirectory('cache');
            }

            if (!is_writable(dirname($imagePath))) {
                throw new \Exception('Cannot write to directory: ' . dirname($imagePath));
            }
            if (!imagepng($img, $imagePath)) {
                throw new \Exception('Failed to save image to: ' . $imagePath);
            }

            imagedestroy($img);
        } catch (\Exception $e) {
            Log::error('Image generation failed: ' . $e->getMessage());
            throw new \Exception('Internal server error');
        }
    }
}
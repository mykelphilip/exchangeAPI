<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CountryController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::post('/countries/refresh', [CountryController::class, 'refresh'])->middleware('throttle:10,1');
Route::get('/countries', [CountryController::class, 'index']);
Route::get('/countries/image', [CountryController::class, 'image']);
Route::get('/countries/{name}', [CountryController::class, 'show']);
Route::delete('/countries/{name}', [CountryController::class, 'destroy']);
Route::get('/status', [CountryController::class, 'status']);
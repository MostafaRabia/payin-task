<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('products/{product}', [\App\Http\Controllers\ProductController::class, 'show']);

Route::post('holds', \App\Http\Controllers\HoldController::class);

Route::post('orders', \App\Http\Controllers\OrderController::class);

Route::post('payments/webhook', \App\Http\Controllers\HandleWebhookController::class);

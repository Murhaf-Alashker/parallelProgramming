<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['message' => 'ok']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Main concurrency simulation endpoint
Route::post('/order', [ProductController::class, 'order']);

// Daily sales report endpoints
Route::get('/daily-sales', [ProductController::class, 'getDailySales']);
Route::get('/daily_reports/{reportName}', [ProductController::class, 'getReport']);

// CRUD APIs
Route::apiResource('categories', CategoryController::class);
Route::apiResource('products', ProductController::class);
Route::apiResource('users', UserController::class);
Route::apiResource('wallets', WalletController::class);
Route::apiResource('orders', OrderController::class);

Route::apiResource('order-items', OrderItemController::class)->parameters([
    'order-items' => 'orderItem',
]);

Route::apiResource('payments', PaymentController::class);

Route::get('/home',[ProductController::class,'homePage']);

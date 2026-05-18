<?php

use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('/order',[ProductController::class,'order']);
Route::get('/',function(){
    return response()->json(['message'=>'ok']);
});
Route::get('/daily-sales',[ProductController::class,'getDailySales']);
Route::get('/daily_reports/{reportName}',[ProductController::class,'getReport']);

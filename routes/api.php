<?php

use App\Http\Controllers\Api\AttendanceApiController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', [UserController::class, '__invoke']);

Route::middleware(['auth:sanctum', 'module:employees', 'permission:employees.attendance.mark'])->post('/attendance/scan', [AttendanceApiController::class, 'scan'])
    ->name('api.attendance.scan');

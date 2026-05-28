<?php

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

Route::post('/info', [DownloadController::class, 'info']);
Route::get('/progress', [DownloadController::class, 'progress']);
Route::get('/file/{filename}', [DownloadController::class, 'file']);

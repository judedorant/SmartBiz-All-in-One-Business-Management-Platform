<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'SmartBiz API', 'version' => 'v1']));

Route::get('/integrations/google/callback', [IntegrationController::class, 'callback'])->name('google.callback');

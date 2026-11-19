<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'aplikasi' => 'Livora API',
        'status' => 'ok',
    ]);
});

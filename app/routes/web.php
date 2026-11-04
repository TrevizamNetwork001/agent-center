<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'Agent Center',
    'phase' => 'recovery-foundation',
]));

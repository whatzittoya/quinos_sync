<?php

use Illuminate\Support\Facades\Route;

// Everything except the API, Sanctum's CSRF route and the health check is
// served by the Vue single-page app.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');

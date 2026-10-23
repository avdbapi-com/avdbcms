<?php

use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Middleware\EnsureDeveloperMode;
use App\Http\Middleware\Language;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Admin Shell Routes
|--------------------------------------------------------------------------
|
| Loaded by routes/admin.php inside the admin group (URL prefix /admin, name
| prefix admin., admin middleware), after every other admin route.
|
*/

Route::controller(AdminSearchController::class)->prefix('shell')->name('shell.')->group(function () {
    // The index is the same for every request with the same ?v= and ?l= (the language), so it skips the Language
    // middleware, which would mark it no-store; the browser then keeps it between pages.
    Route::get('/search-index', 'index')->withoutMiddleware(Language::class)->name('search-index');
    Route::get('/search', 'search')->name('search');
    Route::post('/preferences', 'preferences')->name('preferences');
    Route::get('/moved', 'moved')->name('moved');
});

// Developer pages (Database, Developer zone, Custom code) need Developer mode. The gate is attached once every
// route file has loaded, so a developer route added by any routes/admin/*.php file (they load alphabetically)
// gets it too. For a parameterised route (Custom code is one page of admin.appearance.page) it checks the page
// per request.
app()->booted(function () {
    $names = array_merge(
        (array) config('admin_nav.developer.routes', []),
        array_column((array) config('admin_nav.developer.param_routes', []), 'route'),
    );

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        // middleware() (not gatherMiddleware(), which would freeze the list before the gate is added).
        if ($name !== '' && Str::is($names, $name) && ! in_array(EnsureDeveloperMode::class, $route->middleware(), true)) {
            $route->middleware(EnsureDeveloperMode::class);
        }
    }
});

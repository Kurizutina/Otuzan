<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/forgot-password', [AuthController::class, 'requestPasswordReset'])->middleware('throttle:forgot-password');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1,reset');
// Public routes share one per-IP bucket. A whole campus Wi-Fi shows up as a
// single IP, so the ceiling is sized for dozens of users, not one browser.
Route::get('/catalog', [CatalogController::class, 'publicCatalog'])->middleware('throttle:1200,1,public');
Route::get('/catalog/brands/{brand}/products', [CatalogController::class, 'publicProducts'])->middleware('throttle:1200,1,public');
Route::get('/catalog/best-sellers', [CatalogController::class, 'bestSellers'])->middleware('throttle:1200,1,public');
// Bill documents are served only through a signed, expiring link (an <img>
// tag can't send the bearer token); the signature is the authorization.
// Files uploaded before 10/12 still live in public/ and are found there.
Route::get('/uploads/bill-documents/{filename}', function (string $filename) {
    abort_unless(preg_match('/^[0-9a-f-]{36}\.(jpg|jpeg|png|webp|gif|pdf)$/', $filename) === 1, 404);
    $private = 'bill-documents/'.$filename;
    $path = Storage::disk('local')->exists($private) ? Storage::disk('local')->path($private) : public_path('uploads/'.$private);
    abort_unless(is_file($path), 404);
    return response()->file($path, ['Cache-Control' => 'private, max-age=600']);
})->name('bill-documents.show')->middleware(['signed:relative', 'throttle:1200,1,public']);
// password.changed: a temporary password must be replaced before anything else.
// throttle:600,1,api: one overall per-user ceiling (the app itself polls chat
// every 1s and orders/notifications every 2s, about 120 requests a minute per
// tab, so 600 leaves room for several tabs).
Route::middleware(['auth:sanctum', 'password.changed', 'throttle:600,1,api'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/uploads/bill-documents', function (Request $request) {
        abort_unless($request->user()->Role === 'customer', 403);
        // PHP's own upload_max_filesize can be below the app's 10 MB; above it
        // the upload never arrives and the only error was "failed to upload".
        $maxKb = min(10240, intdiv((int) UploadedFile::getMaxFilesize(), 1024));
        $tooLarge = 'Each bill document must be '.round($maxKb / 1024, 1).' MB or smaller.';
        $data = $request->validate(
            ['document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:'.$maxKb]],
            ['document.max' => $tooLarge, 'document.uploaded' => $tooLarge]
        );
        $file = $data['document'];
        // Private disk (storage/app/private), not public/: bills are only
        // reachable through the signed links handed to people who can see
        // the order (see Payment::paymentNote and the route below).
        $filename = Str::uuid()->toString().'.'.$file->extension();
        $file->storeAs('bill-documents', $filename, 'local');
        // Remember who uploaded it, so a bill can only attach the customer's
        // own documents (PaymentController::store checks this).
        Cache::put('bill-document-owner:'.$filename, $request->user()->UserID, now()->addDay());
        return response()->json(['url' => '/uploads/bill-documents/'.$filename, 'name' => $file->getClientOriginalName()], 201);
    })->middleware('throttle:20,1,uploads');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1,orders');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('throttle:30,1,status');
    // Customer-side post-delivery report (24h window enforced in the
    // controller); the matching admin dismiss lives in the
    // permission:orders.manage group below.
    Route::post('/orders/{order}/report', [OrderController::class, 'report'])->middleware('throttle:10,1,report');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware('throttle:20,1,payments');
    Route::get('/orders/{order}/messages', [MessageController::class, 'index']);
    Route::post('/orders/{order}/messages', [MessageController::class, 'store'])->middleware('throttle:30,1,messages');
    Route::patch('/orders/{order}/messages/read', [MessageController::class, 'markRead']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read', [NotificationController::class, 'markAllRead']);
    Route::middleware('permission:orders.manage')->group(function () {
        Route::get('/admin/orders', [OrderController::class, 'indexAll']);
        Route::get('/admin/revenue', [OrderController::class, 'revenue']);
        Route::get('/admin/customer-segments', [OrderController::class, 'customerSegments']);
        Route::get('/admin/demand-forecast', [OrderController::class, 'demandForecast']);
        Route::patch('/orders/{order}/assign', [OrderController::class, 'assign'])->middleware('throttle:30,1,assign');
        Route::patch('/orders/{order}/report', [OrderController::class, 'clearReport']);
        Route::patch('/payments/{payment}/status', [PaymentController::class, 'updateStatus'])->middleware('throttle:30,1,payment-status');
    });
    Route::middleware('permission:riders.view')->get('/riders', function (Request $request) {
        return response()->json(['riders' => User::where('Role', 'driver')
            ->orderBy('UserName')->orderBy('UserID')->get(['UserID as id', 'UserName as name'])]);
    });
    Route::middleware('permission:catalog.manage')->prefix('admin/catalog')->group(function () {
        Route::get('/options', [CatalogController::class, 'options']);
        Route::get('/services', [CatalogController::class, 'services']);
        Route::post('/services', [CatalogController::class, 'storeService'])->middleware('throttle:30,1,catalog');
        Route::put('/services/{service}', [CatalogController::class, 'updateService'])->middleware('throttle:30,1,catalog');
        Route::delete('/services/{service}', [CatalogController::class, 'destroyService'])->middleware('throttle:30,1,catalog');
        Route::get('/brands', [CatalogController::class, 'brands']);
        Route::post('/brands', [CatalogController::class, 'storeBrand'])->middleware('throttle:30,1,catalog');
        Route::put('/brands/{brand}', [CatalogController::class, 'updateBrand'])->middleware('throttle:30,1,catalog');
        Route::delete('/brands/{brand}', [CatalogController::class, 'destroyBrand'])->middleware('throttle:30,1,catalog');
        Route::get('/products', [CatalogController::class, 'products']);
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::post('/products', [CatalogController::class, 'storeProduct'])->middleware('throttle:30,1,catalog');
        Route::put('/products/{product}', [CatalogController::class, 'updateProduct'])->middleware('throttle:30,1,catalog');
        Route::delete('/products/{product}', [CatalogController::class, 'destroyProduct'])->middleware('throttle:30,1,catalog');
    });
    Route::middleware('permission:accounts.manage')->prefix('admin/accounts')->group(function () {
        Route::get('/{role}', [AccountManagementController::class, 'index']);
        Route::post('/{role}', [AccountManagementController::class, 'store'])->middleware('throttle:30,1,accounts');
        Route::put('/{role}/{account}', [AccountManagementController::class, 'update'])->middleware('throttle:30,1,accounts');
        Route::delete('/{role}/{account}', [AccountManagementController::class, 'destroy'])->middleware('throttle:30,1,accounts');
    });
});
Route::get('/health', fn () => response()->json(['status' => 'ok', 'backend' => 'laravel']));
Route::get('/health/db', function () {
    try {
        DB::select('SELECT 1');
        return response()->json(['status' => 'connected', 'backend' => 'laravel']);
    } catch (Throwable $error) {
        report($error);
        return response()->json(['status' => 'disconnected'], 503);
    }
})->middleware('throttle:1200,1,public');

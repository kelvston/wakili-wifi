<?php

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VoucherController::class, 'portal'])->name('home');

Route::post('payments', [PaymentController::class, 'start'])
    ->middleware('throttle:5,1')
    ->name('payments.start');
Route::get('payments/{payment}', [PaymentController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('payments.show');
Route::get('payments/{payment}/status', [PaymentController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('payments.status');
Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])
    ->middleware('throttle:10,1')
    ->name('payments.cancel');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [VoucherController::class, 'dashboard'])->name('dashboard');

    Route::get('vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::post('vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::post('vouchers/clear-reserved', [VoucherController::class, 'clearReserved'])->name('vouchers.clear-reserved');
    Route::post('vouchers/assign', [VoucherController::class, 'assign'])->name('vouchers.assign');
    Route::post('vouchers/redeem', [VoucherController::class, 'redeem'])->name('vouchers.redeem');
});

require __DIR__.'/settings.php';

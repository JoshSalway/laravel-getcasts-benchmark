<?php

/**
 * API routes for the realworld benchmark.
 * Copy to your test app's routes/ directory.
 */

use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::get('/orders', function () {
    return Order::limit(50)->get();
});

Route::get('/orders/all', function () {
    return Order::all();
});

Route::get('/orders/{order}', function (Order $order) {
    $order->status = 'processing';
    $isDirty = $order->isDirty();

    return response()->json([
        'order' => $order,
        'is_dirty' => $isDirty,
    ]);
});

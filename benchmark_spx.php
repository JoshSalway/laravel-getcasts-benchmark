<?php

/**
 * SPX Benchmark for getCasts() caching.
 * Mirrors the long-running job pattern from laravel/framework#57045.
 *
 * Run with: SPX_ENABLED=1 SPX_FP_FOCUS=wt SPX_REPORT=full php spx_benchmark.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';

$laravelApp = require __DIR__.'/bootstrap/app.php';
$laravelApp->make('Illuminate\Contracts\Console\Kernel')->call('migrate:fresh', ['--force' => true]);

// Create orders table
Schema::create('orders', function ($table) {
    $table->id();
    $table->string('customer_name');
    $table->string('email');
    $table->string('status');
    $table->decimal('subtotal', 10, 2);
    $table->decimal('tax', 10, 2);
    $table->decimal('total', 10, 2);
    $table->integer('quantity');
    $table->boolean('is_fulfilled');
    $table->json('metadata');
    $table->json('shipping_address');
    $table->dateTime('ordered_at');
    $table->dateTime('shipped_at')->nullable();
    $table->timestamps();
});

// Model with realistic casts (11 cast attributes)
class Order extends Model
{
    protected $guarded = [];
    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'quantity' => 'integer',
        'is_fulfilled' => 'boolean',
        'metadata' => 'array',
        'shipping_address' => 'array',
        'ordered_at' => 'datetime',
        'shipped_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

// Seed 1000 rows
$now = now();
$rows = [];
for ($i = 0; $i < 1000; $i++) {
    $rows[] = [
        'customer_name' => "Customer $i",
        'email' => "customer$i@example.com",
        'status' => ['pending', 'processing', 'shipped', 'delivered'][$i % 4],
        'subtotal' => round(10.00 + $i * 0.50, 2),
        'tax' => round((10.00 + $i * 0.50) * 0.1, 2),
        'total' => round((10.00 + $i * 0.50) * 1.1, 2),
        'quantity' => ($i % 10) + 1,
        'is_fulfilled' => $i % 3 === 0,
        'metadata' => json_encode(['source' => 'web', 'utm' => 'google', 'tags' => ['vip', 'repeat']]),
        'shipping_address' => json_encode(["$i Main St", 'city' => 'Sydney', 'zip' => '2000']),
        'ordered_at' => $now->copy()->subDays($i),
        'shipped_at' => $i % 3 === 0 ? $now->copy()->subDays($i - 1) : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
DB::table('orders')->insert($rows);

fwrite(STDERR, "Seeded 1000 orders. Starting long-running job simulation...\n");

// === Simulate a long-running job like #57045 ===
// Process all 1000 orders, accessing every attribute,
// checking dirty state, serializing to array — repeated 10 times
// to simulate a real queue worker processing many jobs.

for ($round = 0; $round < 10; $round++) {
    $orders = Order::all();

    foreach ($orders as $order) {
        // Read every cast attribute
        $_ = $order->customer_name;
        $_ = $order->email;
        $_ = $order->status;
        $_ = $order->subtotal;
        $_ = $order->tax;
        $_ = $order->total;
        $_ = $order->quantity;
        $_ = $order->is_fulfilled;
        $_ = $order->metadata;
        $_ = $order->shipping_address;
        $_ = $order->ordered_at;
        $_ = $order->shipped_at;

        // Modify some attributes
        $order->status = 'processing';
        $order->is_fulfilled = true;
        $order->metadata = array_merge($order->metadata, ['processed' => true]);

        // Check dirty state
        $order->isDirty();

        // Serialize
        $order->toArray();
    }
}

fwrite(STDERR, "Done. Processed 10,000 order iterations.\n");

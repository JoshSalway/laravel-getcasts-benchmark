<?php

/**
 * Real-world benchmark for getCasts() caching.
 *
 * Tests actual Laravel request lifecycle through the HTTP kernel,
 * including middleware, routing, database queries, JSON serialization.
 */

use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Run migrations and seed
$app->make('Illuminate\Contracts\Console\Kernel')->call('migrate:fresh', ['--force' => true]);

echo "Seeding 500 orders...\n";
$now = now();
$rows = [];
for ($i = 0; $i < 500; $i++) {
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
        'shipping_address' => json_encode(['street' => "$i Main St", 'city' => 'Sydney', 'zip' => '2000']),
        'ordered_at' => $now->copy()->subDays($i),
        'shipped_at' => $i % 3 === 0 ? $now->copy()->subDays($i - 1) : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
Illuminate\Support\Facades\DB::table('orders')->insert($rows);
echo "Seeded.\n\n";

function simulateRequest($kernel, string $method, string $uri): Symfony\Component\HttpFoundation\Response
{
    $request = Request::create($uri, $method);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    // Reset app state between requests like a real server would
    $app = $kernel->getApplication();
    if (method_exists($app, 'forgetScopedInstances')) {
        $app->forgetScopedInstances();
    }

    return $response;
}

function benchmark(string $name, Closure $fn, int $runs = 20): float
{
    // Warmup (3 runs)
    for ($i = 0; $i < 3; $i++) {
        $fn();
    }

    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($times);

    // Use median of middle 60% to reduce noise
    $trim = intdiv($runs, 5);
    $trimmed = array_slice($times, $trim, $runs - 2 * $trim);
    $median = $trimmed[intdiv(count($trimmed), 2)];
    $min = $times[0];
    $max = $times[$runs - 1];
    $p95 = $times[intdiv($runs * 95, 100)];

    printf("%-50s  median: %7.2f ms  p95: %7.2f ms  min: %7.2f ms\n", $name, $median, $p95, $min);
    return $median;
}

echo "=== Real-World Laravel 13 Benchmark (PHP " . PHP_VERSION . ") ===\n\n";

// 1. API index - 50 orders (typical paginated endpoint)
echo "--- GET /api/orders (50 orders, JSON response) ---\n";
$r = simulateRequest($kernel, 'GET', '/api/orders');
echo "Response size: " . number_format(strlen($r->getContent())) . " bytes, Status: " . $r->getStatusCode() . "\n";
benchmark('GET /api/orders (50 orders)', function () use ($kernel) {
    simulateRequest($kernel, 'GET', '/api/orders');
});

// 2. API all - 500 orders (large export/dashboard)
echo "\n--- GET /api/orders/all (500 orders, JSON response) ---\n";
$r = simulateRequest($kernel, 'GET', '/api/orders/all');
echo "Response size: " . number_format(strlen($r->getContent())) . " bytes, Status: " . $r->getStatusCode() . "\n";
benchmark('GET /api/orders/all (500 orders)', function () use ($kernel) {
    simulateRequest($kernel, 'GET', '/api/orders/all');
});

// 3. Detail endpoint with dirty check
echo "\n--- GET /api/orders/1 (single model + isDirty) ---\n";
benchmark('GET /api/orders/1 (detail + isDirty)', function () use ($kernel) {
    simulateRequest($kernel, 'GET', '/api/orders/1');
});

// 4. Direct Eloquent operations (no HTTP overhead, isolates model layer)
echo "\n--- Direct Eloquent: load + toArray ---\n";
benchmark('Order::limit(50)->get()->toArray()', function () {
    App\Models\Order::limit(50)->get()->toArray();
});

benchmark('Order::all()->toArray()', function () {
    App\Models\Order::all()->toArray();
});

// 5. Direct Eloquent: attribute access pattern (Blade-like)
echo "\n--- Direct Eloquent: attribute access (Blade pattern) ---\n";
benchmark('Load 50 + access all attrs', function () {
    $orders = App\Models\Order::limit(50)->get();
    foreach ($orders as $order) {
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
    }
});

benchmark('Load 500 + access all attrs', function () {
    $orders = App\Models\Order::all();
    foreach ($orders as $order) {
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
    }
});

// 6. isDirty on collection (observer/event pattern)
echo "\n--- Direct Eloquent: isDirty pattern ---\n";
benchmark('isDirty × 500 (after modifying 3 attrs)', function () {
    $orders = App\Models\Order::all();
    foreach ($orders as $order) {
        $order->status = 'updated';
        $order->is_fulfilled = true;
        $order->shipped_at = now();
        $order->isDirty();
    }
});

echo "\nDone.\n";

# getCasts() Caching Benchmark

Reproducible benchmarks for [laravel/framework#59347](https://github.com/laravel/framework/pull/59347).

This PR caches the `getCasts()` array merge result in an instance property, eliminating redundant `array_merge()` allocations on every attribute access.

## Quick Start

```bash
# Clone
git clone https://github.com/JoshSalway/laravel-getcasts-benchmark.git
cd laravel-getcasts-benchmark

# Create a fresh Laravel 13 app
composer create-project laravel/laravel test-app --prefer-dist --no-interaction
cp benchmark_micro.php test-app/
cp benchmark_spx.php test-app/

# Run micro-benchmarks (no extensions needed)
cd test-app
php benchmark_micro.php
```

## Benchmarks

### benchmark_micro.php

Standalone benchmark that runs against the framework in `vendor/`. No web server or extensions required.

Tests isolated Eloquent operations (getCasts, getAttribute, setAttribute, isDirty, toArray) with 500 models and 7 cast attributes.

**How to compare baseline vs PR:**

```bash
# 1. Run baseline (stock Laravel 13)
php benchmark_micro.php

# 2. Apply the PR patch
cd vendor/laravel/framework
git fetch origin pull/59347/head:pr-59347
git checkout pr-59347

# 3. Run again
cd ../../..
php benchmark_micro.php
```

### benchmark_spx.php

Requires [php-spx](https://github.com/NoiseByNorthwest/php-spx). Simulates a long-running job processing 1,000 models with 11 cast attributes — same methodology as [#57045](https://github.com/laravel/framework/issues/57045).

```bash
# Run with SPX profiling
SPX_ENABLED=1 SPX_FP_FOCUS=wt SPX_REPORT=flat SPX_FP_DEPTH=20 php benchmark_spx.php
```

### benchmark_realworld.php

Full Laravel HTTP kernel benchmark. Simulates real API requests through middleware, routing, and JSON serialization. No web server needed — uses Laravel's test kernel internally.

Requires API routes. Copy `routes/api.php` to your test app's `routes/` directory and register it in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    // ...
)
```

Then run:

```bash
php benchmark_realworld.php
```

## Environment Used for PR Numbers

- PHP 8.4.16 (micro-benchmarks) / PHP 8.5.3 (SPX)
- Laravel 13.2.0
- SQLite (in-memory for micro, file-based for SPX)
- Windows 11 / WSL2 Ubuntu
- SPX 0.4.22

## What to Look For

**Baseline:** `getCasts()` calls `array_merge()` on every invocation. With 11 casts and 1,000 models processed 10 times, that's millions of throwaway array allocations.

**With PR:** `getCasts()` returns a cached array. The cache is invalidated when inputs change (`mergeCasts`, `setKeyName`, `setKeyType`, `setIncrementing`).

In SPX profiles, look for:
- `hasCast()` exclusive time dropping (it calls `getCasts()` on every attribute check)
- `getCasts()` appearing in the profile but with low exclusive time (cache hit)
- Total function call count dropping by ~3.7M
- Overall wall time reduction of 13-24% depending on run conditions

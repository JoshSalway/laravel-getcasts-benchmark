<?php

/**
 * Micro-benchmark for getCasts() caching (laravel/framework#59347).
 *
 * No extensions required. Run from a fresh Laravel 13 app root:
 *   php benchmark_micro.php
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

require __DIR__.'/vendor/autoload.php';

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setEventDispatcher(new Dispatcher);
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::schema()->create('posts', function ($table) {
    $table->increments('id');
    $table->string('title');
    $table->text('body');
    $table->json('metadata');
    $table->boolean('is_published');
    $table->decimal('price', 10, 2);
    $table->integer('view_count');
    $table->dateTime('published_at')->nullable();
    $table->timestamps();
});

class Post extends Model
{
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'is_published' => 'boolean',
        'price' => 'decimal:2',
        'view_count' => 'integer',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

// Seed
for ($i = 0; $i < 500; $i++) {
    Post::create([
        'title' => "Post $i",
        'body' => str_repeat("Content for post $i. ", 10),
        'metadata' => json_encode(['tags' => ['php', 'laravel'], 'version' => $i]),
        'is_published' => $i % 2 === 0,
        'price' => round(9.99 + $i * 0.01, 2),
        'view_count' => $i * 100,
        'published_at' => now()->subDays($i),
    ]);
}

function benchmark(string $name, Closure $fn, int $runs = 10): void
{
    $fn(); // warmup
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($times);
    $median = $times[intdiv($runs, 2)];
    $min = $times[0];
    $max = $times[$runs - 1];
    printf("%-55s  median: %7.2f ms  min: %7.2f ms  max: %7.2f ms\n", $name, $median, $min, $max);
}

echo "=== getCasts() Caching Benchmark ===\n";
echo "PHP " . PHP_VERSION . ", 500 models, 7 cast attributes, 10 runs\n\n";

$model = Post::first();
benchmark('Isolated getCasts() x 100,000', function () use ($model) {
    for ($i = 0; $i < 100_000; $i++) {
        $model->getCasts();
    }
});

benchmark('getAttribute() x 7 attrs x 500 models', function () {
    $posts = Post::all();
    foreach ($posts as $post) {
        $_ = $post->metadata;
        $_ = $post->is_published;
        $_ = $post->price;
        $_ = $post->view_count;
        $_ = $post->published_at;
        $_ = $post->created_at;
        $_ = $post->updated_at;
    }
});

benchmark('setAttribute() x 7 attrs x 500 models', function () {
    $posts = Post::all();
    foreach ($posts as $post) {
        $post->title = 'New Title';
        $post->metadata = ['updated' => true];
        $post->is_published = true;
        $post->price = 19.99;
        $post->view_count = 999;
        $post->published_at = now();
        $post->updated_at = now();
    }
});

$posts = Post::all();
foreach ($posts as $post) {
    $post->title = 'Modified';
}
benchmark('isDirty() x 500 modified models', function () use ($posts) {
    foreach ($posts as $post) {
        $post->isDirty();
    }
});

benchmark('toArray() x 500 models', function () {
    Post::all()->toArray();
});

benchmark('toJson() x 500 models', function () {
    Post::all()->toJson();
});

echo "\nDone.\n";

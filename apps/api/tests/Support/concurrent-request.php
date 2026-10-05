<?php

/*
 * One HTTP request sent through the application's kernel from its own PHP
 * process, and therefore over its own PostgreSQL connection. Started by
 * SendsConcurrentRequests; not a test by itself.
 *
 * The worker connects, then waits on a shared advisory lock that the test holds
 * exclusively. Releasing it lets every waiting worker through at once, so the
 * requests really run at the same time instead of one after another.
 *
 * argv[1]: base64 JSON {method, uri, headers, body, barrier}
 * stdout:  JSON {status, headers, content, started_at, finished_at}
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$spec = json_decode(base64_decode($argv[1] ?? '', true) ?: 'null', true, flags: JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

DB::connection()->getPdo();

DB::select('select pg_advisory_lock_shared(?)', [$spec['barrier']]);
DB::select('select pg_advisory_unlock_shared(?)', [$spec['barrier']]);

$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

foreach ($spec['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}

$request = Request::create(
    $spec['uri'],
    $spec['method'],
    server: $server,
    content: $spec['body'] === null ? null : json_encode($spec['body'], JSON_THROW_ON_ERROR),
);

$startedAt = microtime(true);
$response = $kernel->handle($request);
$finishedAt = microtime(true);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'headers' => $response->headers->all(),
    'content' => $response->getContent(),
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
], JSON_THROW_ON_ERROR));

$kernel->terminate($request, $response);

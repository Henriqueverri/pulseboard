<?php

namespace Tests\Feature\Api\Concerns;

use Illuminate\Http\Response;
use Illuminate\Process\InvokedProcessPool;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Throwable;

/**
 * Real concurrency for PostgreSQL tests: every request runs in its own PHP
 * process with its own connection (tests/Support/concurrent-request.php), so
 * row locks, unique indexes and commits interleave as in production.
 *
 * The test holds an exclusive advisory lock as a barrier. Workers connect and
 * queue behind it with a shared lock; once all of them are waiting (seen in
 * pg_locks) the lock is released and they all proceed at the same instant.
 *
 * Requires committed data, so the test must not run inside RefreshDatabase's
 * transaction.
 */
trait SendsConcurrentRequests
{
    private const WORKER_TIMEOUT_SECONDS = 60;

    private const BARRIER_TIMEOUT_SECONDS = 30;

    /**
     * @param  list<array{method: string, uri: string, key: string, body?: array<string, mixed>|null, headers?: array<string, string>}>  $requests
     * @return list<array{response: TestResponse, started_at: float, finished_at: float}>
     */
    protected function sendConcurrently(array $requests): array
    {
        $barrier = random_int(1, 2_147_483_647);
        $env = $this->workerEnvironment();

        DB::select('select pg_advisory_lock(?)', [$barrier]);

        try {
            $pool = Process::pool(function (Pool $pool) use ($requests, $barrier, $env): void {
                foreach ($requests as $index => $request) {
                    $spec = base64_encode(json_encode([
                        'method' => $request['method'],
                        'uri' => $request['uri'],
                        'headers' => ['Authorization' => 'Bearer '.$request['key'], ...($request['headers'] ?? [])],
                        'body' => $request['body'] ?? null,
                        'barrier' => $barrier,
                    ], JSON_THROW_ON_ERROR));

                    $pool->as((string) $index)
                        ->path(base_path())
                        ->env($env)
                        ->timeout(self::WORKER_TIMEOUT_SECONDS)
                        ->command([PHP_BINARY, base_path('tests/Support/concurrent-request.php'), $spec]);
                }
            })->start();

            $this->waitUntilWorkersAreQueued($pool, $barrier, count($requests));
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$barrier]);
        }

        $results = [];

        foreach ($pool->wait()->collect() as $index => $result) {
            $this->assertTrue($result->successful(), "worker {$index} failed: {$result->errorOutput()}{$result->output()}");

            $output = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

            $results[(int) $index] = [
                'response' => TestResponse::fromBaseResponse(new Response($output['content'], $output['status'], $output['headers'])),
                'started_at' => $output['started_at'],
                'finished_at' => $output['finished_at'],
            ];
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Proves the requests were in flight together: the last one started before
     * the first one finished. Sequential execution would fail this.
     *
     * @param  list<array{response: TestResponse, started_at: float, finished_at: float}>  $results
     */
    protected function assertRequestsOverlapped(array $results): void
    {
        $lastStart = max(array_column($results, 'started_at'));
        $firstFinish = min(array_column($results, 'finished_at'));

        $this->assertLessThan(
            $firstFinish,
            $lastStart,
            sprintf('every request must start before the first one finishes (last start %.4f, first finish %.4f)', $lastStart, $firstFinish),
        );
    }

    /**
     * @param  list<array{response: TestResponse, started_at: float, finished_at: float}>  $results
     * @return list<int>
     */
    protected function sortedStatuses(array $results): array
    {
        $statuses = array_map(fn (array $result): int => $result['response']->getStatusCode(), $results);
        sort($statuses);

        return $statuses;
    }

    private function waitUntilWorkersAreQueued(InvokedProcessPool $pool, int $barrier, int $expected): void
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;

        while ($this->queuedWorkers($barrier) < $expected) {
            if (count($pool->running()) < $expected) {
                $this->failWithWorkerOutput($pool, 'a worker exited before reaching the barrier');
            }

            if (microtime(true) > $deadline) {
                $this->failWithWorkerOutput($pool, 'the workers did not reach the barrier in time');
            }

            usleep(5_000);
        }
    }

    /**
     * A bigint advisory key below 2^32 is reported as classid 0, objid = key, objsubid 1.
     */
    private function queuedWorkers(int $barrier): int
    {
        return (int) DB::scalar(
            "select count(*) from pg_locks
             where locktype = 'advisory' and classid = 0 and objid = ? and objsubid = 1
               and mode = 'ShareLock' and not granted
               and database = (select oid from pg_database where datname = current_database())",
            [$barrier],
        );
    }

    private function failWithWorkerOutput(InvokedProcessPool $pool, string $reason): never
    {
        $pool->stop(1);
        $output = '';

        try {
            foreach ($pool->wait()->collect() as $index => $result) {
                $output .= "\n[worker {$index}] {$result->errorOutput()}{$result->output()}";
            }
        } catch (Throwable) {
            // The reason is still worth reporting without the output.
        }

        $this->fail($reason.$output);
    }

    /**
     * The worker boots the application from scratch, so the database settings are
     * passed explicitly instead of inheriting phpunit.xml's SQLite. The rate
     * limiter uses the database cache store, as in production.
     *
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $connection = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
        ];
    }
}

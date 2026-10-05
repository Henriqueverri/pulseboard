<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Start-up tasks of the production container: migrations, the demo organization, then the
 * AI retention policy (Render Free has no cron, so retention is applied on each start).
 *
 * On PostgreSQL every step runs while holding a session-level advisory lock, so containers
 * booting at the same time (a restart overlapping a deploy) run them one after the other.
 * Every step is idempotent: the second container finds nothing left to do. The lock needs
 * a connection that keeps its session, i.e. the Supabase pooler in session mode (port 5432).
 */
class ReleaseCommand extends Command
{
    private const LOCK_KEY = 7_281_904_551;

    protected $signature = 'pulseboard:release';

    protected $description = 'Run the migrations, the demo seed and the AI retention, serialized across containers';

    public function handle(): int
    {
        $connection = DB::connection();
        $locking = $connection->getDriverName() === 'pgsql';

        if ($locking) {
            $this->components->info('Waiting for the release lock.');
            $connection->select('select pg_advisory_lock(?)', [self::LOCK_KEY]);
        }

        try {
            foreach (['migrate' => ['--force' => true], 'pulseboard:demo' => [], 'pulseboard:ai-prune' => []] as $command => $arguments) {
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    $this->components->error("{$command} failed.");

                    return self::FAILURE;
                }
            }
        } finally {
            if ($locking) {
                $connection->select('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
            }
        }

        return self::SUCCESS;
    }
}

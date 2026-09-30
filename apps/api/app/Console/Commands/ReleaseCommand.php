<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Start-up tasks of the production container: migrations, then the demo organization.
 *
 * On PostgreSQL both steps run while holding a session-level advisory lock, so containers
 * booting at the same time (a restart overlapping a deploy) run them one after the other.
 * Both steps are idempotent: the second container finds nothing left to do. The lock needs
 * a connection that keeps its session, i.e. the Supabase pooler in session mode (port 5432).
 */
class ReleaseCommand extends Command
{
    private const LOCK_KEY = 7_281_904_551;

    protected $signature = 'pulseboard:release';

    protected $description = 'Run the migrations and the demo seed, serialized across containers';

    public function handle(): int
    {
        $connection = DB::connection();
        $locking = $connection->getDriverName() === 'pgsql';

        if ($locking) {
            $this->components->info('Waiting for the release lock.');
            $connection->select('select pg_advisory_lock(?)', [self::LOCK_KEY]);
        }

        try {
            foreach (['migrate' => ['--force' => true], 'pulseboard:demo' => []] as $command => $arguments) {
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

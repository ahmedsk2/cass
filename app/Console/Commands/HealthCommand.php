<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The four questions `/up` deliberately does not answer, plus the two it
 * cannot.
 *
 * `/up` is Laravel's health endpoint and exists for Docker's HEALTHCHECK and
 * for Coolify: it answers "is PHP serving". Every failure below is silent —
 * the site stays up, the pages render, and nothing is delivered, pruned or
 * stored until a human looks:
 *
 *   - a dead `queue:work` under supervisord means no email is ever sent;
 *   - a dead `schedule:work` means no reviewer reminders and no pruning;
 *   - a full or unmounted `cass-storage` volume fails an author's upload;
 *   - migrations are never run at boot (the host rule in CLAUDE.md), so
 *     "deployed but not migrated" is a normal state that 500s every page
 *     touching a new column;
 *   - a misconfigured mailer queues perfectly and delivers nothing.
 *
 * For a human at a terminal, and for a host cron through `--json`. Nothing in
 * the application calls it.
 */
class HealthCommand extends Command
{
    /**
     * A heartbeat or a job older than this is not a slow minute, it is a dead
     * worker. Fifteen minutes is three misses of the five-minute heartbeat in
     * routes/console.php, which is long enough that a deploy restarting
     * supervisord does not raise a false alarm and short enough that a
     * reviewer reminder window is not missed.
     */
    private const int STALE_SECONDS = 900;

    protected $signature = 'cass:health {--json : Machine-readable output}';

    protected $description = 'Check the things /up deliberately does not: the queue, the scheduler, the private disk and pending migrations';

    public function handle(): int
    {
        $rows = [
            $this->checkDatabase(),
            $this->checkMigrations(),
            $this->checkQueue(),
            $this->checkScheduler(),
            $this->checkPrivateDisk(),
            $this->checkMail(),
        ];

        $failed = array_filter($rows, static fn (array $row): bool => $row['ok'] === false);

        if ($this->option('json') === true) {
            $this->line((string) json_encode($rows));
        } else {
            $this->table(
                ['Check', 'Status', 'Detail'],
                array_map(
                    static fn (array $row): array => [$row['name'], $row['ok'] ? 'OK' : 'FAIL', $row['detail']],
                    $rows,
                ),
            );
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkDatabase(): array
    {
        $connection = (string) config('database.default');

        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            return ['name' => 'database', 'ok' => false, 'detail' => "[{$connection}] ".$e->getMessage()];
        }

        return ['name' => 'database', 'ok' => true, 'detail' => "connection [{$connection}] answers"];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkMigrations(): array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $this->laravel->make('migrator');

            if (! $migrator->repositoryExists()) {
                return ['name' => 'migrations', 'ok' => false, 'detail' => 'the migrations table does not exist - run php artisan migrate --force'];
            }

            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [$this->laravel->databasePath('migrations')]),
            );

            $pending = array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            return ['name' => 'migrations', 'ok' => false, 'detail' => $e->getMessage()];
        }

        if ($pending !== []) {
            return [
                'name' => 'migrations',
                'ok' => false,
                'detail' => count($pending).' pending, first ['.((string) $pending[0]).'] - run php artisan migrate --force',
            ];
        }

        return ['name' => 'migrations', 'ok' => true, 'detail' => count($ran).' applied, none pending'];
    }

    /**
     * A backlog is a worker that is slow or a burst that is large; a backlog
     * whose oldest job is a quarter of an hour old is a worker that is dead.
     * Only the second is a failure, because the first is what a decision-email
     * run looks like.
     *
     * The `jobs` table is read whatever `QUEUE_CONNECTION` says: a deployment
     * that switched the connection to `sync` by accident is exactly the state
     * this command exists to surface, and rows already queued still have to be
     * drained by somebody.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkQueue(): array
    {
        $table = (string) config('queue.connections.database.table', 'jobs');
        // DB_QUEUE_CONNECTION is unset in this deployment, so this is null and
        // resolves to the default connection; named here anyway so the check
        // follows the queue if that ever changes.
        $connection = config('queue.connections.database.connection');
        $now = CarbonImmutable::now();

        try {
            $oldest = DB::connection(is_string($connection) ? $connection : null)
                ->table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now->getTimestamp())
                ->min('available_at');
        } catch (Throwable $e) {
            return ['name' => 'queue', 'ok' => false, 'detail' => "[{$table}] ".$e->getMessage()];
        }

        if ($oldest === null) {
            return ['name' => 'queue', 'ok' => true, 'detail' => 'no job waiting'];
        }

        $age = $now->getTimestamp() - (int) $oldest;

        if ($age >= self::STALE_SECONDS) {
            return [
                'name' => 'queue',
                'ok' => false,
                'detail' => 'oldest waiting job is '.$this->humanise($age).' old - is queue:work running?',
            ];
        }

        return ['name' => 'queue', 'ok' => true, 'detail' => 'oldest waiting job is '.$this->humanise($age).' old'];
    }

    /**
     * Laravel records no "last run" for the scheduler anywhere, so this reads
     * the heartbeat routes/console.php writes every five minutes. No key at all
     * is the worst of the two answers: it means schedule:work has not run since
     * the cache was last cleared.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkScheduler(): array
    {
        $beat = Cache::get('cass:scheduler-heartbeat');

        if (! is_string($beat) || $beat === '') {
            return ['name' => 'scheduler', 'ok' => false, 'detail' => 'no heartbeat has ever been written - is schedule:work running?'];
        }

        try {
            $at = CarbonImmutable::parse($beat);
        } catch (Throwable) {
            return ['name' => 'scheduler', 'ok' => false, 'detail' => "unreadable heartbeat [{$beat}]"];
        }

        $age = CarbonImmutable::now()->getTimestamp() - $at->getTimestamp();

        if ($age >= self::STALE_SECONDS) {
            return [
                'name' => 'scheduler',
                'ok' => false,
                'detail' => 'last heartbeat '.$this->humanise($age).' ago - is schedule:work running?',
            ];
        }

        return ['name' => 'scheduler', 'ok' => true, 'detail' => 'last heartbeat '.$this->humanise($age).' ago'];
    }

    /**
     * A real write, a real read-back and a real delete on the disk every
     * abstract lands on. `config/filesystems.php` sets `throw => false` on this
     * disk, so a failure is a `false` return rather than an exception, and both
     * are handled.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkPrivateDisk(): array
    {
        $path = 'health-check-'.Str::random(16).'.tmp';
        $payload = 'cass:health '.CarbonImmutable::now()->toIso8601String();

        try {
            $disk = Storage::disk('local');

            if ($disk->put($path, $payload) === false) {
                return ['name' => 'private disk', 'ok' => false, 'detail' => 'could not write a probe file'];
            }

            $readBack = $disk->get($path);
            $disk->delete($path);
        } catch (Throwable $e) {
            return ['name' => 'private disk', 'ok' => false, 'detail' => $e->getMessage()];
        }

        if ($readBack !== $payload) {
            return ['name' => 'private disk', 'ok' => false, 'detail' => 'a probe file did not read back'];
        }

        return ['name' => 'private disk', 'ok' => true, 'detail' => 'wrote, read and deleted a probe file'];
    }

    /**
     * Only enforced in production: the test suite runs on the `array` mailer
     * and a local run on `log`, and calling either of those a failure would
     * make this command useless everywhere it is cheapest to run.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');

        if (! $this->laravel->environment('production')) {
            return ['name' => 'mail', 'ok' => true, 'detail' => "mailer [{$mailer}]; only enforced in production"];
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return ['name' => 'mail', 'ok' => false, 'detail' => "mailer is [{$mailer}] in production - nothing is delivered"];
        }

        $host = (string) config("mail.mailers.{$mailer}.host");

        if ($host === '') {
            return ['name' => 'mail', 'ok' => false, 'detail' => "mailer [{$mailer}] has no host configured"];
        }

        return ['name' => 'mail', 'ok' => true, 'detail' => "mailer [{$mailer}] via {$host}"];
    }

    private function humanise(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}

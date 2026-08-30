<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Console\Command;

/**
 * Rebuilds the body search index on the log rows that predate it.
 *
 * `clickhouse:migrate` swaps the index definition (0005), which is metadata only
 * and takes effect immediately for new parts. Parts written before the swap keep
 * answering body searches by full scan — correct, just unaccelerated — until this
 * command rewrites their index files.
 *
 * It is deliberately not part of `clickhouse:migrate`: the mutation reads every
 * existing part, and migrate runs on container boot, once per role. Re-issuing
 * this on every deploy would re-mutate the whole table while it is also ingesting.
 */
class ClickHouseMaterializeIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clickhouse:materialize-index
        {--table=otel_logs : The table holding the index}
        {--index=idx_lower_body : The data skipping index to rebuild}
        {--no-wait : Start the mutation and return without following it}
        {--timeout=1800 : Seconds to follow the mutation before giving up on it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild a ClickHouse data skipping index across the parts that predate it';

    /**
     * The first ClickHouse release carrying the `text` index this rebuilds.
     */
    private const MINIMUM_VERSION = [26, 2];

    /**
     * How long to wait between progress polls, in seconds.
     */
    private const POLL_SECONDS = 2;

    /**
     * Identifiers reach ClickHouse as literal SQL, so they are pinned to a shape
     * that cannot carry anything else. They come from the operator's own command
     * line rather than from a request, but a mutation is not the place to relax.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function handle(ClickHouseClient $client): int
    {
        $table = (string) $this->option('table');
        $index = (string) $this->option('index');

        foreach ([$table, $index] as $identifier) {
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                $this->components->error(sprintf('[%s] is not a valid ClickHouse identifier.', $identifier));

                return self::FAILURE;
            }
        }

        try {
            if (! $this->assertVersion($client) || ! $this->assertIndexExists($client, $table, $index)) {
                return self::FAILURE;
            }

            $this->components->task(
                sprintf('Starting MATERIALIZE INDEX %s on %s', $index, $table),
                fn () => $client->execute(sprintf('ALTER TABLE %s MATERIALIZE INDEX %s', $table, $index)),
            );

            if ($this->option('no-wait')) {
                $this->components->info('Mutation queued. Follow it in system.mutations.');

                return self::SUCCESS;
            }

            return $this->follow($client, $table);
        } catch (ClickHouseException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Refuse to run against a server too old for the index being rebuilt.
     *
     * Materializing a `text` index on a server that has none would fail anyway;
     * saying so up front is the difference between an explanation and a stack
     * trace.
     */
    private function assertVersion(ClickHouseClient $client): bool
    {
        $rows = $client->select('SELECT version() AS version');
        $version = (string) ($rows[0]['version'] ?? '');

        [$major, $minor] = array_pad(array_map('intval', explode('.', $version, 3)), 2, 0);
        [$requiredMajor, $requiredMinor] = self::MINIMUM_VERSION;

        if ($major > $requiredMajor || ($major === $requiredMajor && $minor >= $requiredMinor)) {
            return true;
        }

        $this->components->error(sprintf(
            'ClickHouse %s is below the %d.%d floor the text body index requires. Upgrade the server first; searches keep working on the old index until you do.',
            $version === '' ? '(unknown)' : $version,
            $requiredMajor,
            $requiredMinor,
        ));

        return false;
    }

    /**
     * Confirm the index is actually declared before mutating every part for it.
     */
    private function assertIndexExists(ClickHouseClient $client, string $table, string $index): bool
    {
        $rows = $client->select(
            'SELECT type_full, expr FROM system.data_skipping_indices
             WHERE database = currentDatabase() AND table = {table:String} AND name = {index:String}',
            ['table' => $table, 'index' => $index],
        );

        if ($rows === []) {
            $this->components->error(sprintf(
                'No index named [%s] on [%s]. Run `php artisan clickhouse:migrate` first.',
                $index,
                $table,
            ));

            return false;
        }

        $this->components->twoColumnDetail($index, sprintf(
            '%s ON %s',
            (string) ($rows[0]['type_full'] ?? 'unknown'),
            (string) ($rows[0]['expr'] ?? 'unknown'),
        ));

        return true;
    }

    /**
     * Follow the mutation to completion, reporting the parts left to rewrite.
     */
    private function follow(ClickHouseClient $client, string $table): int
    {
        $deadline = time() + max(1, (int) $this->option('timeout'));

        while (true) {
            $rows = $client->select(
                "SELECT is_done, parts_to_do, latest_fail_reason FROM system.mutations
                 WHERE database = currentDatabase() AND table = {table:String}
                   AND command LIKE '%MATERIALIZE INDEX%'
                 ORDER BY create_time DESC LIMIT 1",
                ['table' => $table],
            );

            $mutation = $rows[0] ?? null;

            if ($mutation === null) {
                $this->components->warn('The mutation is no longer listed in system.mutations.');

                return self::SUCCESS;
            }

            $failure = (string) ($mutation['latest_fail_reason'] ?? '');

            if ($failure !== '') {
                $this->components->error('The mutation is failing: '.$failure);

                return self::FAILURE;
            }

            if ((int) ($mutation['is_done'] ?? 0) === 1) {
                $this->components->info(sprintf('Index rebuilt across every part of %s.', $table));

                return self::SUCCESS;
            }

            if (time() >= $deadline) {
                $this->components->warn(sprintf(
                    'Still %d parts to rewrite after %ds. The mutation keeps running server-side; follow it in system.mutations.',
                    (int) ($mutation['parts_to_do'] ?? 0),
                    (int) $this->option('timeout'),
                ));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('Parts left to rewrite', (string) ($mutation['parts_to_do'] ?? 0));

            sleep(self::POLL_SECONDS);
        }
    }
}

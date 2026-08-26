<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClickHouseMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clickhouse:migrate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the ClickHouse database and apply the log storage schema';

    /**
     * Apply every ClickHouse schema file. Each file must contain a single
     * idempotent statement, so the command may safely be run repeatedly.
     */
    public function handle(ClickHouseClient $client): int
    {
        $database = $client->database();

        try {
            $this->components->task(
                sprintf('Creating database [%s]', $database),
                fn () => $client->execute(
                    sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $database),
                    withDatabase: false,
                ),
            );

            foreach ($this->schemaFiles() as $path) {
                $statement = trim(File::get($path), " \t\n\r\0\x0B;");

                $this->components->task(
                    sprintf('Applying [%s]', basename($path)),
                    fn () => $client->execute($statement),
                );
            }
        } catch (ClickHouseException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('ClickHouse schema is up to date.');

        return self::SUCCESS;
    }

    /**
     * The schema files to apply, in lexicographical order.
     *
     * @return array<int, string>
     */
    private function schemaFiles(): array
    {
        $directory = database_path('clickhouse');

        if (! File::isDirectory($directory)) {
            return [];
        }

        $files = File::glob($directory.'/*.sql');

        sort($files);

        return $files;
    }
}

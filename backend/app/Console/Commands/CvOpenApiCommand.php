<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates the OpenAPI document from the code.
 *
 * The document is the contract the extension and backend meet at, so it is
 * generated from the routes, the form requests and the controllers rather than
 * written beside them, and the generated file is committed: the extension's
 * client is built from it without this application running.
 *
 * The generator types a document by reading Eloquent models' own schema, so it
 * needs a migrated database. This gives it a throwaway in-memory one, because
 * the document describes the code and must not be able to come out differently
 * on a machine whose database is behind, missing or full.
 */
class CvOpenApiCommand extends Command
{
    protected $signature = 'cv:openapi
        {--path= : Write the document here instead of the configured path}';

    protected $description = 'Regenerate the OpenAPI document from the code.';

    public function handle(): int
    {
        Config::set('database.connections.openapi', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'openapi');

        // An in-memory database lives as long as its connection, so the
        // migrated schema is only there for the export if nothing purges it
        // in between. Purging first discards whatever was resolved before.
        DB::purge();

        if ($this->callSilent('migrate', ['--force' => true, '--database' => 'openapi']) !== self::SUCCESS) {
            $this->components->error('The migrations did not run, so the document cannot be generated.');

            return self::FAILURE;
        }

        $path = $this->option('path');

        return $this->call('scramble:export', is_string($path) && $path !== '' ? ['--path' => $path] : []);
    }
}

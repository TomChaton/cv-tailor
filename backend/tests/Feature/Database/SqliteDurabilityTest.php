<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteDurabilityTest extends TestCase
{
    /**
     * `FULL` fsyncs on every commit. `NORMAL` fsyncs at checkpoints instead,
     * so a host losing power can lose the last few writes - an acceptable
     * trade for a captured posting, which can be captured again, and not for
     * the master CV, which is the one thing in the database nobody can
     * regenerate.
     *
     * SQLite reports the modes as integers: 0 off, 1 normal, 2 full. The
     * journal mode has no test beside this one because an in-memory database
     * reports `memory` whatever is configured.
     */
    public function test_every_commit_is_fsynced(): void
    {
        $this->assertSame(2, DB::scalar('pragma synchronous'));
    }
}

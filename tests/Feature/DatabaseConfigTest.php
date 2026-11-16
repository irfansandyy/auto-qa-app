<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseConfigTest extends TestCase
{
    public function test_sqlite_uses_wal_and_a_busy_timeout(): void
    {
        $this->assertSame(5000, config('database.connections.sqlite.busy_timeout'));
        $this->assertSame('wal', config('database.connections.sqlite.journal_mode'));
        $this->assertTrue((bool) config('database.connections.sqlite.foreign_key_constraints'));
    }
}

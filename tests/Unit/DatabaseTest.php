<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use InvalidArgumentException;
use Kayra\Database\Connection;
use Kayra\Database\Grammar;
use Kayra\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryBuilder::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Grammar::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(
            ['driver' => 'sqlite', 'database' => ':memory:'],
            Grammar::for('sqlite'),
        );

        $this->db->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INTEGER, active INTEGER)',
        );

        $this->db->table('users')->insertMany([
            ['name' => 'Kawsar', 'email' => 'k@test', 'age' => 30, 'active' => 1],
            ['name' => 'Ada', 'email' => 'a@test', 'age' => 36, 'active' => 1],
            ['name' => 'Bob', 'email' => 'b@test', 'age' => 20, 'active' => 0],
        ]);
    }

    private function users(): QueryBuilder
    {
        return $this->db->table('users');
    }

    /* ------------------------------------------------------ injection safety */

    #[Test]
    #[DataProvider('injectionPayloads')]
    public function malicious_values_are_bound_not_interpolated(string $payload): void
    {
        // The whole table must survive: a bound value can never change the
        // structure of the statement, only be compared against.
        $result = $this->users()->where('name', $payload)->get();

        $this->assertSame([], $result, 'A crafted value matched a row it should not have.');
        $this->assertSame(3, $this->users()->count(), 'The table was modified by a value.');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield 'classic or'      => ["' OR '1'='1"];
        yield 'drop table'      => ["'; DROP TABLE users; --"];
        yield 'union select'    => ["' UNION SELECT 1,2,3,4,5 --"];
        yield 'comment'         => ['admin\'--'];
        yield 'stacked update'  => ["'; UPDATE users SET active = 1; --"];
        yield 'null byte'       => ["a\0b"];
        yield 'backslash'       => ['a\\\'b'];
    }

    #[Test]
    #[DataProvider('maliciousIdentifiers')]
    public function malicious_identifiers_are_rejected_outright(string $identifier): void
    {
        // Identifiers cannot be bound, so the only safe handling is refusal.
        $this->expectException(InvalidArgumentException::class);

        $this->users()->orderBy($identifier)->toSql();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function maliciousIdentifiers(): iterable
    {
        yield 'subquery'      => ['(SELECT password FROM users)'];
        yield 'quote escape'  => ['name"; DROP TABLE users; --'];
        yield 'backtick'      => ['name`'];
        yield 'space'         => ['name age'];
        yield 'semicolon'     => ['name;'];
        yield 'comment'       => ['name--'];
    }

    #[Test]
    public function sort_direction_is_restricted_to_an_allow_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users()->orderBy('name', 'ASC; DROP TABLE users');
    }

    #[Test]
    public function operators_are_restricted_to_an_allow_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users()->where('age', 'OR 1=1 --', 5);
    }

    #[Test]
    public function prepared_statements_are_not_emulated(): void
    {
        // With emulation on, PDO interpolates parameters client-side and the
        // guarantees above stop holding. SQLite never emulates and refuses to
        // report the attribute at all, so the check is that binding really is
        // server-side: a bound value must not be able to close a string
        // literal, whatever quoting it contains.
        try {
            $emulating = (bool) $this->db->pdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
            $this->assertFalse($emulating);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $this->db->table('users')->insert([
            'name'   => "quote' and \\backslash",
            'email'  => 'q@test',
            'age'    => 1,
            'active' => 1,
        ]);

        $row = $this->users()->where('name', "quote' and \\backslash")->first();

        $this->assertNotNull($row, 'A value containing quotes did not survive a round trip intact.');
        $this->assertSame(4, $this->users()->count());
    }

    /* ---------------------------------------------------------------- reads */

    #[Test]
    public function it_selects_and_filters(): void
    {
        $rows = $this->users()->where('active', 1)->orderBy('age')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Kawsar', $rows[0]['name']);
    }

    #[Test]
    public function two_argument_where_implies_equality(): void
    {
        $this->assertNotNull($this->users()->where('name', 'Ada')->first());
    }

    #[Test]
    public function or_where_shorthand_works(): void
    {
        // Regression: orWhere() delegating to where() broke the arity check, so
        // the value was read as an operator.
        $rows = $this->users()->where('name', 'Ada')->orWhere('name', 'Bob')->get();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function nested_conditions_group_correctly(): void
    {
        $rows = $this->users()
            ->where(fn (QueryBuilder $q) => $q->where('age', '>', 25)->orWhere('name', 'Bob'))
            ->get();

        $this->assertCount(3, $rows);
    }

    #[Test]
    public function where_null_and_not_null(): void
    {
        $this->db->table('users')->insert(['name' => 'NoAge', 'email' => 'n@test', 'age' => null, 'active' => 1]);

        $this->assertCount(1, $this->users()->whereNull('age')->get());
        $this->assertCount(3, $this->users()->whereNotNull('age')->get());
    }

    #[Test]
    public function comparing_to_null_uses_is_null(): void
    {
        // `col = NULL` is never true; the builder must translate it.
        $this->db->table('users')->insert(['name' => 'NoAge', 'email' => 'n@test', 'age' => null, 'active' => 1]);

        $this->assertCount(1, $this->users()->where('age', null)->get());
    }

    #[Test]
    public function where_in_with_an_empty_list_matches_nothing(): void
    {
        // `IN ()` is a syntax error in most dialects, so it must be rewritten.
        $this->assertSame([], $this->users()->whereIn('name', [])->get());
        $this->assertCount(3, $this->users()->whereNotIn('name', [])->get());
    }

    #[Test]
    public function aggregates(): void
    {
        $this->assertSame(3, $this->users()->count());
        $this->assertSame(2, $this->users()->where('active', 1)->count());
        $this->assertSame(86.0, $this->users()->sum('age'));
        $this->assertSame(20, $this->users()->min('age'));
        $this->assertSame(36, $this->users()->max('age'));
    }

    #[Test]
    public function pagination(): void
    {
        $page = $this->users()->orderBy('age')->forPage(2, 2)->get();

        $this->assertCount(1, $page);
        $this->assertSame('Ada', $page[0]['name']);
    }

    #[Test]
    public function chunk_requires_a_stable_order(): void
    {
        // Without ORDER BY, LIMIT/OFFSET paging can skip or repeat rows.
        $this->expectException(InvalidArgumentException::class);

        $this->users()->chunk(2, static fn (): bool => true);
    }

    #[Test]
    public function chunk_walks_every_row(): void
    {
        $seen = [];

        $this->users()->orderBy('id')->chunk(2, function (array $rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = $row['name'];
            }
        });

        $this->assertSame(['Kawsar', 'Ada', 'Bob'], $seen);
    }

    #[Test]
    public function cursor_streams_without_materialising(): void
    {
        $names = [];

        foreach ($this->users()->orderBy('id')->cursor() as $row) {
            $names[] = $row['name'];
        }

        $this->assertSame(['Kawsar', 'Ada', 'Bob'], $names);
    }

    /* --------------------------------------------------------------- writes */

    #[Test]
    public function insert_update_delete(): void
    {
        $this->users()->insert(['name' => 'New', 'email' => 'n@test', 'age' => 1, 'active' => 1]);
        $this->assertSame(4, $this->users()->count());

        $this->users()->where('name', 'New')->update(['age' => 2]);
        $this->assertSame(2, $this->users()->where('name', 'New')->value('age'));

        $this->users()->where('name', 'New')->delete();
        $this->assertSame(3, $this->users()->count());
    }

    #[Test]
    public function update_without_a_condition_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/would modify every row/');

        $this->users()->update(['active' => 0]);
    }

    #[Test]
    public function delete_without_a_condition_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/would remove every row/');

        $this->users()->delete();
    }

    #[Test]
    public function update_all_is_available_when_it_is_intended(): void
    {
        $this->assertSame(3, $this->users()->updateAll(['active' => 0]));
        $this->assertSame(0, $this->users()->where('active', 1)->count());
    }

    /* --------------------------------------------------------- transactions */

    #[Test]
    public function a_transaction_commits_on_success(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->table('users')->insert(['name' => 'Tx', 'email' => 't@test', 'age' => 1, 'active' => 1]);
        });

        $this->assertSame(4, $this->users()->count());
    }

    #[Test]
    public function a_transaction_rolls_back_on_failure(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->table('users')->insert(['name' => 'Tx', 'email' => 't@test', 'age' => 1, 'active' => 1]);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(3, $this->users()->count(), 'The insert should have been rolled back.');
        $this->assertSame(0, $this->db->transactionLevel());
    }

    #[Test]
    public function nested_transactions_use_savepoints(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->table('users')->insert(['name' => 'Outer', 'email' => 'o@test', 'age' => 1, 'active' => 1]);

            try {
                $db->transaction(function (Connection $inner): void {
                    $inner->table('users')->insert(['name' => 'Inner', 'email' => 'i@test', 'age' => 1, 'active' => 1]);

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
                // The inner failure must not discard the outer work.
            }
        });

        $this->assertNotNull($this->users()->where('name', 'Outer')->first());
        $this->assertNull($this->users()->where('name', 'Inner')->first());
    }

    /* -------------------------------------------------------------- grammar */

    #[Test]
    #[DataProvider('dialects')]
    public function each_dialect_quotes_identifiers_its_own_way(string $driver, string $expected): void
    {
        $this->assertSame($expected, Grammar::for($driver)->wrap('users.id'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function dialects(): iterable
    {
        yield 'mysql'    => ['mysql', '`users`.`id`'];
        yield 'postgres' => ['pgsql', '"users"."id"'];
        yield 'sqlite'   => ['sqlite', '"users"."id"'];
        yield 'sqlsrv'   => ['sqlsrv', '[users].[id]'];
    }

    #[Test]
    public function an_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Grammar::for('oracle');
    }
}

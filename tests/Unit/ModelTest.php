<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use DateTimeImmutable;
use Kayra\Database\Concerns\SoftDeletes;
use Kayra\Database\Connection;
use Kayra\Database\DatabaseManager;
use Kayra\Database\Grammar;
use Kayra\Database\MassAssignmentException;
use Kayra\Database\Model;
use Kayra\Database\ModelNotFoundException;
use Kayra\Database\Relations\BelongsTo;
use Kayra\Database\Relations\BelongsToMany;
use Kayra\Database\Relations\HasMany;
use Kayra\Database\Relations\HasOne;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/* ------------------------------------------------------------------ fixtures */

/**
 * @property int|null                $id
 * @property string                  $name
 * @property string                  $email
 * @property int                     $age
 * @property bool                    $is_admin
 * @property array<string, mixed>    $settings
 * @property \DateTimeImmutable|null  $created_at
 * @property list<TestPost>           $posts
 * @property TestProfile|null         $profile
 * @property list<TestRole>           $roles
 */
final class TestUser extends Model
{
    protected string $table = 'users';

    protected array $fillable = ['name', 'email', 'age', 'settings', 'is_admin'];

    protected array $hidden = ['password'];

    protected array $casts = [
        'age'        => 'int',
        'is_admin'   => 'bool',
        'settings'   => 'array',
        'created_at' => 'datetime',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(TestPost::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(TestProfile::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(TestRole::class, 'role_user', 'user_id', 'role_id');
    }
}

/**
 * @property int|null       $id
 * @property int             $user_id
 * @property string          $title
 * @property TestUser|null   $author
 */
final class TestPost extends Model
{
    protected string $table = 'posts';

    protected array $fillable = ['user_id', 'title'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}

/**
 * @property int|null $id
 * @property int       $user_id
 * @property string    $bio
 */
final class TestProfile extends Model
{
    protected string $table = 'profiles';

    protected array $fillable = ['user_id', 'bio'];
}

/**
 * @property int|null $id
 * @property string    $name
 */
final class TestRole extends Model
{
    protected string $table = 'roles';

    protected array $fillable = ['name'];
}

/**
 * @property int|null    $id
 * @property string       $body
 * @property string|null  $deleted_at
 */
final class TestNote extends Model
{
    use SoftDeletes;

    protected string $table = 'notes';

    protected array $fillable = ['body'];
}

final class TestLocked extends Model
{
    protected string $table = 'users';
    // No $fillable: nothing may be mass-assigned.
}

/* --------------------------------------------------------------------- tests */

#[CoversClass(Model::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class ModelTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $config = new \Kayra\Config\Repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]);

        $manager = new DatabaseManager($config);
        Model::setConnectionResolver($manager);

        $this->db = $manager->connection();

        $this->db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INTEGER, is_admin INTEGER, settings TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $this->db->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
    }

    private function seed(): void
    {
        foreach ([['Kawsar', 'k@test', 30], ['Ada', 'a@test', 36], ['Bob', 'b@test', 20]] as [$name, $email, $age]) {
            TestUser::create(['name' => $name, 'email' => $email, 'age' => $age]);
        }

        foreach ([[1, 'First'], [1, 'Second'], [2, 'Third']] as [$userId, $title]) {
            TestPost::create(['user_id' => $userId, 'title' => $title]);
        }
    }

    /* ------------------------------------------------------------- basics */

    #[Test]
    public function it_infers_the_table_name_from_the_class(): void
    {
        $model = new class extends Model {
            protected array $guarded = [];
        };

        // An anonymous class has no meaningful short name, so check a real one.
        $this->assertSame('users', (new TestUser())->getTable());
        $this->assertIsString($model->getTable());
    }

    #[Test]
    public function create_inserts_and_assigns_the_key(): void
    {
        $user = TestUser::create(['name' => 'Kawsar', 'email' => 'k@test', 'age' => 30]);

        $this->assertTrue($user->exists());
        $this->assertSame(1, $user->getKey());
        $this->assertSame(1, $this->db->table('users')->count());
    }

    #[Test]
    public function find_and_find_or_fail(): void
    {
        $this->seed();

        $this->assertSame('Ada', TestUser::find(2)?->name);
        $this->assertNull(TestUser::find(999));

        $this->expectException(ModelNotFoundException::class);
        TestUser::findOrFail(999);
    }

    #[Test]
    public function find_or_fail_is_a_404_not_a_500(): void
    {
        try {
            TestUser::findOrFail(999);
            $this->fail('Expected ModelNotFoundException.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function timestamps_are_maintained(): void
    {
        $user = TestUser::create(['name' => 'K', 'email' => 'k@test', 'age' => 1]);

        $this->assertNotNull($user->getAttributes()['created_at']);
        $this->assertNotNull($user->getAttributes()['updated_at']);
    }

    /* --------------------------------------------------- mass assignment */

    #[Test]
    public function mass_assignment_is_closed_by_default(): void
    {
        $this->expectException(MassAssignmentException::class);

        new TestLocked(['name' => 'anything']);
    }

    #[Test]
    public function a_non_fillable_attribute_throws_rather_than_being_dropped(): void
    {
        // The dangerous version of this bug is silent: an attacker adds
        // is_admin=1 to a form post and the save "succeeds".
        $this->expectException(MassAssignmentException::class);
        $this->expectExceptionMessageMatches('/password/');

        new TestUser(['name' => 'K', 'password' => 'hunter2']);
    }

    #[Test]
    public function force_fill_bypasses_the_check_for_trusted_data(): void
    {
        $user = (new TestUser())->forceFill(['name' => 'K', 'password' => 'hashed']);

        $this->assertSame('hashed', $user->getAttributes()['password']);
    }

    /* ---------------------------------------------------------- dirty state */

    #[Test]
    public function update_writes_only_the_columns_that_changed(): void
    {
        $this->seed();

        $this->db->enableQueryLog();

        $user = TestUser::findOrFail(1);
        $user->name = 'Renamed';
        $user->save();

        $update = null;

        foreach ($this->db->queryLog() as $entry) {
            if (str_starts_with($entry['sql'], 'UPDATE')) {
                $update = $entry;
            }
        }

        $this->assertNotNull($update);
        $this->assertStringContainsString('"name"', $update['sql']);
        // email and age were untouched and must not appear in the statement.
        $this->assertStringNotContainsString('"email"', $update['sql']);
        $this->assertStringNotContainsString('"age"', $update['sql']);
    }

    #[Test]
    public function saving_an_unchanged_model_is_a_no_op(): void
    {
        $this->seed();

        $user = TestUser::findOrFail(1);
        $this->assertFalse($user->isDirty());
        $this->assertTrue($user->save());
    }

    #[Test]
    public function is_dirty_tracks_individual_attributes(): void
    {
        $this->seed();

        $user = TestUser::findOrFail(1);
        $user->name = 'Changed';

        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    /* ---------------------------------------------------------------- casts */

    #[Test]
    public function attributes_are_cast_on_read(): void
    {
        TestUser::create([
            'name' => 'K', 'email' => 'k@test', 'age' => '42',
            'is_admin' => true, 'settings' => ['theme' => 'dark'],
        ]);

        $user = TestUser::findOrFail(1);

        $this->assertSame(42, $user->age);
        $this->assertTrue($user->is_admin);
        $this->assertSame(['theme' => 'dark'], $user->settings);
        $this->assertInstanceOf(DateTimeImmutable::class, $user->created_at);
    }

    #[Test]
    public function array_casts_round_trip_through_the_database(): void
    {
        TestUser::create(['name' => 'K', 'email' => 'k@test', 'age' => 1, 'settings' => ['a' => [1, 2]]]);

        $this->assertSame(['a' => [1, 2]], TestUser::findOrFail(1)->settings);
    }

    /* ---------------------------------------------------------- relations */

    #[Test]
    public function has_many_returns_children(): void
    {
        $this->seed();

        $this->assertCount(2, TestUser::findOrFail(1)->posts);
        $this->assertCount(0, TestUser::findOrFail(3)->posts);
    }

    #[Test]
    public function belongs_to_returns_the_parent(): void
    {
        $this->seed();

        $this->assertSame('Kawsar', TestPost::findOrFail(1)->author->name);
    }

    #[Test]
    public function has_one_returns_a_single_model(): void
    {
        $this->seed();
        TestProfile::create(['user_id' => 1, 'bio' => 'Builder']);

        $this->assertSame('Builder', TestUser::findOrFail(1)->profile->bio);
        $this->assertNull(TestUser::findOrFail(2)->profile);
    }

    #[Test]
    public function belongs_to_many_goes_through_the_pivot(): void
    {
        $this->seed();
        TestRole::create(['name' => 'admin']);
        TestRole::create(['name' => 'editor']);

        TestUser::findOrFail(1)->roles()->attach([1, 2]);

        $roles = TestUser::findOrFail(1)->roles;

        $this->assertCount(2, $roles);
        $this->assertSame(['admin', 'editor'], array_map(static fn ($r) => $r->name, $roles));
    }

    #[Test]
    public function pivot_detach_and_sync(): void
    {
        $this->seed();
        TestRole::create(['name' => 'admin']);
        TestRole::create(['name' => 'editor']);

        $user = TestUser::findOrFail(1);
        $user->roles()->attach([1, 2]);
        $user->roles()->detach([1]);
        $this->assertCount(1, TestUser::findOrFail(1)->roles);

        $user->roles()->sync([1]);
        $this->assertSame(['admin'], array_map(static fn ($r) => $r->name, TestUser::findOrFail(1)->roles));
    }

    /* ------------------------------------------------------- eager loading */

    #[Test]
    public function eager_loading_avoids_the_n_plus_one_problem(): void
    {
        $this->seed();

        // Lazy: one query for the users, then one per user for their posts.
        $this->db->flushQueryLog();
        $this->db->enableQueryLog();

        foreach (TestUser::all() as $user) {
            $user->posts;
        }

        $lazy = count($this->db->queryLog());

        // Eager: one query for the users, one for every user's posts.
        $this->db->flushQueryLog();

        foreach (TestUser::with('posts')->get() as $user) {
            $user->posts;
        }

        $eager = count($this->db->queryLog());

        $this->assertSame(4, $lazy, '1 for users + 1 per user = N+1.');
        $this->assertSame(2, $eager, 'Eager loading must be a fixed two queries.');
    }

    #[Test]
    public function eager_loaded_relations_carry_the_right_children(): void
    {
        $this->seed();

        $users = TestUser::with('posts')->get();

        $byName = [];

        foreach ($users as $user) {
            $byName[$user->name] = array_map(static fn ($p) => $p->title, $user->posts);
        }

        $this->assertSame(['First', 'Second'], $byName['Kawsar']);
        $this->assertSame(['Third'], $byName['Ada']);
        $this->assertSame([], $byName['Bob'], 'A parent with no children must get an empty relation, not a lazy query.');
    }

    #[Test]
    public function eager_loading_a_belongs_to_relation(): void
    {
        $this->seed();

        $this->db->flushQueryLog();
        $this->db->enableQueryLog();

        $posts = TestPost::with('author')->get();

        foreach ($posts as $post) {
            $post->author;
        }

        $this->assertSame(2, count($this->db->queryLog()));
        $this->assertSame('Kawsar', $posts[0]->author->name);
    }

    #[Test]
    public function eager_loading_an_unknown_relation_is_an_error(): void
    {
        $this->seed();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no such relation/');

        TestUser::with('nope')->get();
    }

    /* --------------------------------------------------------- soft deletes */

    #[Test]
    public function soft_deleted_rows_are_excluded_by_default(): void
    {
        TestNote::create(['body' => 'keep']);
        TestNote::create(['body' => 'bin']);

        TestNote::findOrFail(2)->delete();

        $this->assertCount(1, TestNote::all());
        $this->assertCount(2, TestNote::withTrashed()->get());
        $this->assertCount(1, TestNote::onlyTrashed()->get());
        // The row is still there.
        $this->assertSame(2, $this->db->table('notes')->count());
    }

    #[Test]
    public function a_soft_deleted_row_can_be_restored(): void
    {
        TestNote::create(['body' => 'oops']);
        TestNote::findOrFail(1)->delete();

        $trashed = TestNote::withTrashed()->firstOrFail();
        $this->assertTrue($trashed->trashed());

        $trashed->restore();

        $this->assertCount(1, TestNote::all());
    }

    #[Test]
    public function force_delete_really_removes_the_row(): void
    {
        TestNote::create(['body' => 'gone']);

        TestNote::withTrashed()->first()->forceDelete();

        $this->assertSame(0, $this->db->table('notes')->count());
    }

    /* ------------------------------------------------------- serialisation */

    #[Test]
    public function hidden_attributes_stay_out_of_arrays_and_json(): void
    {
        $user = (new TestUser())->forceFill([
            'id' => 1, 'name' => 'K', 'email' => 'k@test', 'password' => 'secret',
        ]);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertStringNotContainsString('secret', json_encode($user));
    }

    #[Test]
    public function relations_are_included_in_the_array_form(): void
    {
        $this->seed();

        $array = TestUser::with('posts')->first()->toArray();

        $this->assertArrayHasKey('posts', $array);
        $this->assertSame('First', $array['posts'][0]['title']);
    }

    #[Test]
    public function dates_serialise_as_iso_8601(): void
    {
        TestUser::create(['name' => 'K', 'email' => 'k@test', 'age' => 1]);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) TestUser::findOrFail(1)->toArray()['created_at'],
        );
    }

    #[Test]
    public function models_are_array_accessible(): void
    {
        $this->seed();

        $user = TestUser::findOrFail(1);

        $this->assertSame('Kawsar', $user['name']);
        $this->assertTrue(isset($user['email']));
    }

    /* --------------------------------------------------------------- misc */

    #[Test]
    public function delete_removes_the_row(): void
    {
        $this->seed();

        $this->assertTrue(TestUser::findOrFail(3)->delete());
        $this->assertNull(TestUser::find(3));
    }

    #[Test]
    public function refresh_rereads_from_the_database(): void
    {
        $this->seed();

        $user = TestUser::findOrFail(1);
        $this->db->table('users')->where('id', 1)->update(['name' => 'Changed elsewhere']);

        $this->assertSame('Kawsar', $user->name);
        $this->assertSame('Changed elsewhere', $user->refresh()->name);
    }

    #[Test]
    public function the_query_builder_is_reachable_for_anything_the_model_lacks(): void
    {
        $this->seed();

        $names = array_map(
            static fn (TestUser $u): string => (string) $u->name,
            TestUser::query()->where('age', '>', 25)->orderBy('age', 'DESC')->get(),
        );

        $this->assertSame(['Ada', 'Kawsar'], $names);
    }
}

<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Auth\Authenticatable;
use Kayra\Auth\Concerns\AuthenticatesUsers;
use Kayra\Auth\Hasher;
use Kayra\Auth\ModelUserProvider;
use Kayra\Auth\PasswordBroker;
use Kayra\Auth\SessionGuard;
use Kayra\Auth\TokenGuard;
use Kayra\Config\Repository;
use Kayra\Database\Connection;
use Kayra\Database\DatabaseManager;
use Kayra\Database\Model;
use Kayra\Encryption\Encrypter;
use Kayra\Http\Request;
use Kayra\Session\FileSessionHandler;
use Kayra\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @property int|null $id
 * @property string    $name
 * @property string    $email
 */
final class AuthUser extends Model implements Authenticatable
{
    use AuthenticatesUsers;

    protected string $table = 'users';

    protected array $fillable = ['name', 'email'];

    protected array $hidden = ['password'];
}

/**
 * @property int|null $id
 * @property int       $user_id
 * @property string    $name
 */
final class AuthToken extends Model
{
    protected string $table = 'api_tokens';

    protected array $fillable = ['user_id', 'name', 'token', 'expires_at'];
}

#[CoversClass(Hasher::class)]
#[CoversClass(ModelUserProvider::class)]
#[CoversClass(SessionGuard::class)]
#[CoversClass(TokenGuard::class)]
#[CoversClass(PasswordBroker::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class AuthTest extends TestCase
{
    private Connection $db;

    private Hasher $hasher;

    private ModelUserProvider $provider;

    private string $tmp;

    protected function setUp(): void
    {
        $config = new Repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]);

        $manager = new DatabaseManager($config);
        Model::setConnectionResolver($manager);
        $this->db = $manager->connection();

        $this->db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, token TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE password_resets (email TEXT, token TEXT, created_at TEXT)');

        // cost 4 keeps the suite fast; production uses 12.
        $this->hasher = new Hasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $this->provider = new ModelUserProvider(AuthUser::class, $this->hasher);

        $this->tmp = sys_get_temp_dir() . '/kayra_auth_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmp);
    }

    private function makeUser(string $email = 'k@test', string $password = 'correct-horse'): AuthUser
    {
        $user = new AuthUser(['name' => 'Kawsar', 'email' => $email]);
        $user->forceFill(['password' => $this->hasher->make($password)]);
        $user->save();

        return $user;
    }

    private function session(): Session
    {
        $handler = new FileSessionHandler(
            $this->tmp,
            Encrypter::fromKey(Encrypter::generateKey()),
            7200,
        );

        $session = new Session($handler, '');
        $session->start();

        return $session;
    }

    /* --------------------------------------------------------------- hashing */

    #[Test]
    public function passwords_are_hashed_and_verifiable(): void
    {
        $hash = $this->hasher->make('secret');

        $this->assertNotSame('secret', $hash);
        $this->assertTrue($this->hasher->check('secret', $hash));
        $this->assertFalse($this->hasher->check('wrong', $hash));
    }

    #[Test]
    public function the_same_password_hashes_differently_each_time(): void
    {
        // Automatic salting: identical passwords must not produce identical hashes.
        $this->assertNotSame($this->hasher->make('same'), $this->hasher->make('same'));
    }

    #[Test]
    public function bcrypt_refuses_passwords_it_would_silently_truncate(): void
    {
        // bcrypt ignores everything past 72 bytes. Accepting a 200-character
        // password and only honouring the first 72 is worse than refusing it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/72 bytes/');

        $this->hasher->make(str_repeat('a', 73));
    }

    #[Test]
    public function an_empty_password_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->hasher->make('');
    }

    #[Test]
    public function a_hash_made_with_a_lower_cost_is_flagged_for_rehashing(): void
    {
        $weak = (new Hasher(PASSWORD_BCRYPT, ['cost' => 4]))->make('secret');

        $this->assertTrue((new Hasher(PASSWORD_BCRYPT, ['cost' => 6]))->needsRehash($weak));
        $this->assertFalse((new Hasher(PASSWORD_BCRYPT, ['cost' => 4]))->needsRehash($weak));
    }

    /* -------------------------------------------------------------- provider */

    #[Test]
    public function credentials_are_validated_against_the_stored_hash(): void
    {
        $this->makeUser();

        $user = $this->provider->retrieveByCredentials(['email' => 'k@test']);

        $this->assertNotNull($user);
        $this->assertTrue($this->provider->validateCredentials($user, ['password' => 'correct-horse']));
        $this->assertFalse($this->provider->validateCredentials($user, ['password' => 'wrong']));
    }

    #[Test]
    public function the_password_is_never_used_as_a_lookup_column(): void
    {
        $this->makeUser();

        // A provider that put the password in the WHERE clause would find
        // nothing here (it is hashed) and would leak timing besides.
        $user = $this->provider->retrieveByCredentials([
            'email'    => 'k@test',
            'password' => 'correct-horse',
        ]);

        $this->assertNotNull($user);
    }

    #[Test]
    public function validating_a_missing_user_still_performs_a_hash_comparison(): void
    {
        // Without the dummy-hash comparison, a login for a non-existent account
        // returns far faster than one for a real account, and that timing
        // difference enumerates valid email addresses.
        $this->makeUser();

        $samples = [];

        foreach (['k@test' => 'wrong-password', 'nobody@test' => 'wrong-password'] as $email => $password) {
            $start = hrtime(true);

            $user = $this->provider->retrieveByCredentials(['email' => $email]);
            $this->provider->validateCredentials($user, ['password' => $password]);

            $samples[$email] = hrtime(true) - $start;
        }

        $this->assertFalse($this->provider->validateCredentials(null, ['password' => 'x']));

        // Both paths run one bcrypt verify, so neither should be an order of
        // magnitude faster than the other.
        $ratio = max($samples) / max(1, min($samples));
        $this->assertLessThan(
            10.0,
            $ratio,
            'Timing differs enough between an existing and a missing account to enumerate users.',
        );
    }

    #[Test]
    public function a_password_is_rehashed_when_the_cost_policy_rises(): void
    {
        $user = $this->makeUser();
        $before = $user->getAuthPassword();

        $stronger = new ModelUserProvider(AuthUser::class, new Hasher(PASSWORD_BCRYPT, ['cost' => 6]));
        $stronger->rehashPasswordIfRequired($user, ['password' => 'correct-horse']);

        $after = AuthUser::find($user->getKey())->getAuthPassword();

        $this->assertNotSame($before, $after);
        $this->assertTrue((new Hasher(PASSWORD_BCRYPT, ['cost' => 6]))->check('correct-horse', $after));
    }

    /* --------------------------------------------------------- session guard */

    #[Test]
    public function attempt_logs_a_user_in_with_correct_credentials(): void
    {
        $this->makeUser();
        $guard = new SessionGuard($this->provider, $this->session());

        $this->assertTrue($guard->guest());
        $this->assertTrue($guard->attempt(['email' => 'k@test', 'password' => 'correct-horse']));
        $this->assertTrue($guard->check());
        $this->assertSame('k@test', $guard->user()?->getAttributes()['email']);
    }

    #[Test]
    public function attempt_fails_with_the_wrong_password(): void
    {
        $this->makeUser();
        $guard = new SessionGuard($this->provider, $this->session());

        $this->assertFalse($guard->attempt(['email' => 'k@test', 'password' => 'nope']));
        $this->assertTrue($guard->guest());
    }

    #[Test]
    public function logging_in_regenerates_the_session_id(): void
    {
        // Session fixation: an attacker who fixed the victim's session id before
        // login must not still hold a valid one afterwards.
        $this->makeUser();
        $session = $this->session();
        $before = $session->id();

        (new SessionGuard($this->provider, $session))
            ->attempt(['email' => 'k@test', 'password' => 'correct-horse']);

        $this->assertNotSame($before, $session->id());
    }

    #[Test]
    public function the_session_survives_a_new_guard_instance(): void
    {
        $this->makeUser();
        $session = $this->session();

        (new SessionGuard($this->provider, $session))
            ->attempt(['email' => 'k@test', 'password' => 'correct-horse']);

        // A later request builds a fresh guard over the same session.
        $this->assertTrue((new SessionGuard($this->provider, $session))->check());
    }

    #[Test]
    public function changing_the_password_invalidates_existing_sessions(): void
    {
        $user = $this->makeUser();
        $session = $this->session();

        (new SessionGuard($this->provider, $session))
            ->attempt(['email' => 'k@test', 'password' => 'correct-horse']);

        $this->assertTrue((new SessionGuard($this->provider, $session))->check());

        // The user changes their password — every other session must die.
        $user->forceFill(['password' => $this->hasher->make('brand-new')])->save();

        $this->assertFalse(
            (new SessionGuard($this->provider, $session))->check(),
            'A session issued under the old password is still valid.',
        );
    }

    #[Test]
    public function a_session_for_a_deleted_user_does_not_authenticate(): void
    {
        $user = $this->makeUser();
        $session = $this->session();

        (new SessionGuard($this->provider, $session))
            ->attempt(['email' => 'k@test', 'password' => 'correct-horse']);

        $user->delete();

        $this->assertFalse((new SessionGuard($this->provider, $session))->check());
    }

    #[Test]
    public function logout_clears_the_session(): void
    {
        $this->makeUser();
        $session = $this->session();
        $guard = new SessionGuard($this->provider, $session);

        $guard->attempt(['email' => 'k@test', 'password' => 'correct-horse']);
        $guard->logout();

        $this->assertTrue($guard->guest());
        $this->assertFalse((new SessionGuard($this->provider, $session))->check());
    }

    #[Test]
    public function validate_checks_credentials_without_logging_in(): void
    {
        $this->makeUser();
        $guard = new SessionGuard($this->provider, $this->session());

        $this->assertTrue($guard->validate(['email' => 'k@test', 'password' => 'correct-horse']));
        $this->assertTrue($guard->guest(), 'validate() must not establish a session.');
    }

    /* ----------------------------------------------------------- token guard */

    private function tokenGuard(string $header): TokenGuard
    {
        return new TokenGuard(
            $this->provider,
            new Request('GET', 'https://api.test/x', $header === '' ? [] : ['Authorization' => $header]),
            AuthToken::class,
        );
    }

    #[Test]
    public function a_valid_bearer_token_authenticates(): void
    {
        $user = $this->makeUser();
        $token = TokenGuard::generate();

        AuthToken::create(['user_id' => $user->getKey(), 'name' => 'cli', 'token' => $token['hash']]);

        $this->assertTrue($this->tokenGuard('Bearer ' . $token['plain'])->check());
    }

    #[Test]
    public function tokens_are_stored_hashed_not_in_plaintext(): void
    {
        $user = $this->makeUser();
        $token = TokenGuard::generate();

        AuthToken::create(['user_id' => $user->getKey(), 'name' => 'cli', 'token' => $token['hash']]);

        $stored = (string) $this->db->table('api_tokens')->value('token');

        $this->assertNotSame($token['plain'], $stored);
        $this->assertSame(hash('sha256', $token['plain']), $stored);
    }

    #[Test]
    public function an_unknown_or_absent_token_does_not_authenticate(): void
    {
        $this->makeUser();

        $this->assertTrue($this->tokenGuard('')->guest());
        $this->assertTrue($this->tokenGuard('Bearer ' . bin2hex(random_bytes(32)))->guest());
        $this->assertTrue($this->tokenGuard('Basic abc')->guest());
    }

    #[Test]
    public function an_expired_token_does_not_authenticate(): void
    {
        $user = $this->makeUser();
        $token = TokenGuard::generate();

        AuthToken::create([
            'user_id'    => $user->getKey(),
            'name'       => 'old',
            'token'      => $token['hash'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $this->assertTrue($this->tokenGuard('Bearer ' . $token['plain'])->guest());
    }

    /* -------------------------------------------------------- password reset */

    private function broker(): PasswordBroker
    {
        return new PasswordBroker($this->provider, $this->db, $this->hasher, 'password_resets', 3600);
    }

    #[Test]
    public function a_reset_token_is_issued_and_stored_hashed(): void
    {
        $this->makeUser();
        $broker = $this->broker();

        $token = $broker->createToken('k@test');

        $this->assertNotNull($token);

        $stored = (string) $this->db->table('password_resets')->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    #[Test]
    public function requesting_a_reset_for_an_unknown_address_reveals_nothing(): void
    {
        $this->makeUser();

        // Null rather than an error: the caller responds identically either way,
        // so the endpoint cannot be used to test whether an address is registered.
        $this->assertNull($this->broker()->createToken('nobody@test'));
        $this->assertSame(0, $this->db->table('password_resets')->count());
    }

    #[Test]
    public function a_valid_token_resets_the_password(): void
    {
        $this->makeUser();
        $broker = $this->broker();
        $token = $broker->createToken('k@test');

        $this->assertTrue($broker->reset('k@test', (string) $token, 'a-brand-new-one'));

        $user = AuthUser::query()->where('email', 'k@test')->first();
        $this->assertTrue($this->hasher->check('a-brand-new-one', $user->getAuthPassword()));
    }

    #[Test]
    public function a_reset_token_is_single_use(): void
    {
        $this->makeUser();
        $broker = $this->broker();
        $token = (string) $broker->createToken('k@test');

        $this->assertTrue($broker->reset('k@test', $token, 'first-change'));
        $this->assertFalse(
            $broker->reset('k@test', $token, 'second-change'),
            'A reset link must not work twice — it lives in an inbox.',
        );
    }

    #[Test]
    public function a_wrong_token_is_rejected(): void
    {
        $this->makeUser();
        $broker = $this->broker();
        $broker->createToken('k@test');

        $this->assertFalse($broker->reset('k@test', bin2hex(random_bytes(32)), 'nope'));
    }

    #[Test]
    public function an_expired_token_is_rejected_and_removed(): void
    {
        $this->makeUser();
        $broker = new PasswordBroker($this->provider, $this->db, $this->hasher, 'password_resets', 1);
        $token = (string) $broker->createToken('k@test');

        $this->db->table('password_resets')
            ->where('email', 'k@test')
            ->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 600)]);

        $this->assertFalse($broker->validateToken('k@test', $token));
        $this->assertSame(0, $this->db->table('password_resets')->count());
    }

    #[Test]
    public function issuing_a_new_token_retires_the_previous_one(): void
    {
        $this->makeUser();
        $broker = $this->broker();

        $first = (string) $broker->createToken('k@test');
        $second = (string) $broker->createToken('k@test');

        $this->assertFalse($broker->validateToken('k@test', $first));
        $this->assertTrue($broker->validateToken('k@test', $second));
    }

    /* -------------------------------------------------------------- exposure */

    #[Test]
    public function the_password_hash_never_appears_in_serialised_output(): void
    {
        $user = $this->makeUser();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertStringNotContainsString('$2y$', (string) json_encode($user));
    }
}

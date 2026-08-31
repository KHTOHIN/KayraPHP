<?php

declare(strict_types=1);

namespace Kayra\Session;

use Kayra\Utils\Str;

/**
 * A request-scoped session.
 *
 * Deliberately independent of PHP's native `session_*` functions: those keep
 * state in process globals, which is exactly what breaks under a long-running
 * worker. This is an ordinary object held in the container's scoped bucket, so
 * it is created per request and discarded with it.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** Flash keys that should survive exactly one more request. */
    private const FLASH_NEW = '_flash.new';
    private const FLASH_OLD = '_flash.old';

    private const TOKEN_KEY = '_token';

    private bool $started = false;

    private bool $dirty = false;

    public function __construct(
        private readonly SessionHandler $handler,
        private string $id = '',
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if ($this->id !== '' && $this->isValidId($this->id)) {
            $this->data = $this->handler->read($this->id);
        } else {
            $this->id = $this->newId();
            $this->data = [];
        }

        $this->ageFlashData();
        $this->ensureToken();
    }

    /* --------------------------------------------------------------------
     | Identity
     * -------------------------------------------------------------------- */

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Issue a new session id, keeping the data.
     *
     * Must be called on privilege change — logging in above all. Without it an
     * attacker who fixed the victim's session id before login still holds a
     * valid id afterwards (session fixation).
     */
    public function regenerate(bool $destroyOld = true): void
    {
        $previous = $this->id;
        $this->id = $this->newId();
        $this->dirty = true;

        if ($destroyOld && $previous !== '') {
            $this->handler->destroy($previous);
        }
    }

    /**
     * Throw the contents away and start again — for logout.
     */
    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
        $this->ensureToken();
    }

    private function newId(): string
    {
        // 32 bytes of entropy, hex-encoded: not guessable, and safe in a cookie.
        return bin2hex(random_bytes(32));
    }

    private function isValidId(string $id): bool
    {
        // Reject anything that is not exactly our own format before it reaches
        // the storage layer, so a crafted id cannot become a file path.
        return preg_match('/^[a-f0-9]{64}$/', $id) === 1;
    }

    /* --------------------------------------------------------------------
     | Data
     * -------------------------------------------------------------------- */

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flush(): void
    {
        $this->data = [];
        $this->dirty = true;
        $this->ensureToken();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /* --------------------------------------------------------------------
     | Flash data
     * -------------------------------------------------------------------- */

    /**
     * Store a value for the next request only.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);

        $new = $this->get(self::FLASH_NEW, []);
        $new = is_array($new) ? $new : [];
        $new[] = $key;

        $this->put(self::FLASH_NEW, array_values(array_unique(array_filter($new, is_string(...)))));
    }

    /**
     * Keep this request's flash data for one more request.
     */
    public function reflash(): void
    {
        $old = $this->get(self::FLASH_OLD, []);
        $new = $this->get(self::FLASH_NEW, []);

        $this->put(self::FLASH_NEW, array_values(array_unique(array_filter([
            ...(is_array($old) ? $old : []),
            ...(is_array($new) ? $new : []),
        ], is_string(...)))));
    }

    /**
     * Age flash data: what was written last request is readable now, and is
     * deleted at the end of this one.
     */
    private function ageFlashData(): void
    {
        $expired = $this->get(self::FLASH_OLD, []);

        foreach (is_array($expired) ? $expired : [] as $key) {
            if (is_string($key)) {
                unset($this->data[$key]);
            }
        }

        $this->data[self::FLASH_OLD] = $this->get(self::FLASH_NEW, []);
        $this->data[self::FLASH_NEW] = [];
    }

    /* --------------------------------------------------------------------
     | CSRF token
     * -------------------------------------------------------------------- */

    public function token(): string
    {
        $this->ensureToken();

        /** @var string $token */
        $token = $this->data[self::TOKEN_KEY];

        return $token;
    }

    private function ensureToken(): void
    {
        if (!isset($this->data[self::TOKEN_KEY]) || !is_string($this->data[self::TOKEN_KEY])) {
            $this->data[self::TOKEN_KEY] = Str::random(40);
            $this->dirty = true;
        }
    }

    /**
     * Compare a candidate token against the session's, in constant time.
     */
    public function verifyToken(string $candidate): bool
    {
        return $candidate !== '' && hash_equals($this->token(), $candidate);
    }

    /* --------------------------------------------------------------------
     | Persistence
     * -------------------------------------------------------------------- */

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Whether anything changed and the session needs writing.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        $this->handler->write($this->id, $this->data);
        $this->dirty = false;
    }
}

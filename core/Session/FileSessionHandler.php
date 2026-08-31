<?php

declare(strict_types=1);

namespace Kayra\Session;

use Kayra\Encryption\EncryptionException;
use Kayra\Encryption\Encrypter;
use RuntimeException;

/**
 * Stores sessions as encrypted files.
 *
 * Payloads are encrypted at rest, so a readable storage directory — a backup, a
 * shared host, a misconfigured volume — does not hand over session contents.
 * Encryption is authenticated, so a file edited on disk fails to decrypt rather
 * than feeding modified data back into the application.
 */
final class FileSessionHandler implements SessionHandler
{
    public function __construct(
        private readonly string $directory,
        private readonly Encrypter $encrypter,
        private readonly int $lifetime = 7200,
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create session directory [{$this->directory}].");
        }
    }

    public function read(string $id): array
    {
        $path = $this->path($id);

        if (!is_file($path)) {
            return [];
        }

        // Expiry is enforced on read as well as by gc(), so a stale file that
        // has not been collected yet is still treated as gone.
        if (filemtime($path) + $this->lifetime < time()) {
            $this->destroy($id);

            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $data = $this->encrypter->decrypt($contents);
        } catch (EncryptionException) {
            // Tampered, or written under a previous APP_KEY. Either way the
            // safe response is a fresh, empty session.
            $this->destroy($id);

            return [];
        }

        return is_array($data) ? $data : [];
    }

    public function write(string $id, array $data): void
    {
        $path = $this->path($id);
        $temporary = $path . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporary, $this->encrypter->encrypt($data), LOCK_EX) === false) {
            throw new RuntimeException("Unable to write session [{$id}].");
        }

        @chmod($temporary, 0o600);

        // Rename is atomic, so a concurrent reader never sees a partial file.
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to persist session [{$id}].");
        }
    }

    public function destroy(string $id): void
    {
        $path = $this->path($id);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function gc(int $lifetime): int
    {
        $removed = 0;
        $cutoff = time() - $lifetime;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Map a session id to a file path.
     *
     * The id is validated as pure hex before use. Without that check an id is a
     * path fragment supplied by the client, and `../` in it becomes arbitrary
     * file access.
     */
    private function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw new RuntimeException('Invalid session id.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . 'sess_' . $id;
    }
}

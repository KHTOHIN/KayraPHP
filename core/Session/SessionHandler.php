<?php

declare(strict_types=1);

namespace Kayra\Session;

/**
 * Where session payloads are stored.
 */
interface SessionHandler
{
    /**
     * @return array<string, mixed> Empty when the id is unknown or expired.
     */
    public function read(string $id): array;

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data): void;

    public function destroy(string $id): void;

    /**
     * Remove sessions older than $lifetime seconds.
     *
     * @return int Number removed.
     */
    public function gc(int $lifetime): int;
}

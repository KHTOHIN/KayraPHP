<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * PSR-7 uploaded file.
 */
final class UploadedFile implements UploadedFileInterface
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_OK         => 'No error.',
        UPLOAD_ERR_INI_SIZE   => 'The file exceeds upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'The file exceeds MAX_FILE_SIZE in the form.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];

    private bool $moved = false;

    public function __construct(
        private readonly string|StreamInterface $source,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if (!array_key_exists($error, self::UPLOAD_ERRORS)) {
            throw new InvalidArgumentException("Invalid upload error code [{$error}].");
        }
    }

    /**
     * Normalise the $_FILES superglobal into a PSR-7 uploaded-file tree.
     *
     * Handles PHP's transposed array layout for nested/multiple inputs, which is
     * the part hand-rolled implementations usually get wrong.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, UploadedFileInterface|array<array-key, mixed>>
     */
    public static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (!isset($value['tmp_name'])) {
                $normalized[$key] = self::normalize($value);

                continue;
            }

            $normalized[$key] = is_array($value['tmp_name'])
                ? self::normalize(self::transpose($value))
                : self::fromSpec($value);
        }

        return $normalized;
    }

    /**
     * Turn ['tmp_name' => [a, b], 'size' => [1, 2], ...] into
     * [['tmp_name' => a, 'size' => 1], ['tmp_name' => b, 'size' => 2]].
     *
     * @param array<string, array<array-key, mixed>> $spec
     * @return array<array-key, array<string, mixed>>
     */
    private static function transpose(array $spec): array
    {
        $result = [];

        foreach (array_keys($spec['tmp_name']) as $index) {
            foreach (['tmp_name', 'size', 'error', 'name', 'type'] as $field) {
                if (isset($spec[$field]) && is_array($spec[$field])) {
                    $result[$index][$field] = $spec[$field][$index] ?? null;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function fromSpec(array $spec): self
    {
        return new self(
            is_string($spec['tmp_name']) ? $spec['tmp_name'] : '',
            isset($spec['size']) ? (int) $spec['size'] : null,
            isset($spec['error']) ? (int) $spec['error'] : UPLOAD_ERR_OK,
            isset($spec['name']) && is_string($spec['name']) ? $spec['name'] : null,
            isset($spec['type']) && is_string($spec['type']) ? $spec['type'] : null,
        );
    }

    public function getStream(): StreamInterface
    {
        $this->assertUsable();

        if ($this->source instanceof StreamInterface) {
            return $this->source;
        }

        return Stream::fromFile($this->source, 'r');
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();

        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path cannot be empty.');
        }

        $directory = dirname($targetPath);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException("Upload target directory [{$directory}] is not writable.");
        }

        if ($this->source instanceof StreamInterface) {
            $this->writeStreamTo($this->source, $targetPath);
            $this->moved = true;

            return;
        }

        // move_uploaded_file() enforces that the file really came from an upload;
        // it only applies under a real SAPI request, hence the sapi check.
        $moved = PHP_SAPI === 'cli'
            ? @rename($this->source, $targetPath)
            : @move_uploaded_file($this->source, $targetPath);

        if ($moved === false) {
            throw new RuntimeException("Unable to move uploaded file to [{$targetPath}].");
        }

        $this->moved = true;
    }

    private function writeStreamTo(StreamInterface $stream, string $targetPath): void
    {
        $target = Stream::fromFile($targetPath, 'w');

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (!$stream->eof()) {
            $target->write($stream->read(1_048_576));
        }

        $target->close();
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && !$this->moved;
    }

    /**
     * A safe filename derived from the client-supplied name.
     *
     * Never trust getClientFilename() directly: it is attacker-controlled and is
     * a classic path-traversal vector.
     */
    public function safeFilename(): string
    {
        $name = basename(str_replace('\\', '/', $this->clientFilename ?? ''));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = ltrim($name, '.');

        return $name === '' ? 'upload' : substr($name, 0, 255);
    }

    private function assertUsable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot use upload: ' . self::UPLOAD_ERRORS[$this->error]);
        }

        if ($this->moved) {
            throw new RuntimeException('The uploaded file has already been moved.');
        }
    }
}

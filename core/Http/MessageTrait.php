<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * Header, body and protocol handling shared by Request and Response.
 *
 * Header names are stored with their original casing for {@see getHeaders()},
 * while lookups go through a lower-cased index so they stay case-insensitive —
 * the behaviour PSR-7 requires and the detail most hand-written implementations
 * get wrong by simply lower-casing everything.
 */
trait MessageTrait
{
    /** @var array<string, list<string>> Original-cased name => values. */
    private array $headers = [];

    /** @var array<string, string> Lower-cased name => original-cased name. */
    private array $headerNames = [];

    private string $protocolVersion = '1.1';

    private ?StreamInterface $stream = null;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\NoDiscard('withProtocolVersion() returns a new message; the receiver is unchanged')]
    public function withProtocolVersion(string $version): static
    {
        if ($version === $this->protocolVersion) {
            return $this;
        }

        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $original = $this->headerNames[strtolower($name)] ?? null;

        return $original === null ? [] : $this->headers[$original];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    #[\NoDiscard('withHeader() returns a new message; the receiver is unchanged')]
    public function withHeader(string $name, mixed $value): static
    {
        $this->assertHeaderName($name);
        $values = $this->normaliseHeaderValue($name, $value);
        $lower = strtolower($name);

        $clone = clone $this;

        // Replacing a header must not leave the old casing behind.
        if (isset($clone->headerNames[$lower])) {
            unset($clone->headers[$clone->headerNames[$lower]]);
        }

        $clone->headerNames[$lower] = $name;
        $clone->headers[$name] = $values;

        return $clone;
    }

    #[\NoDiscard('withAddedHeader() returns a new message; the receiver is unchanged')]
    public function withAddedHeader(string $name, mixed $value): static
    {
        $this->assertHeaderName($name);
        $values = $this->normaliseHeaderValue($name, $value);
        $lower = strtolower($name);

        $clone = clone $this;

        if (isset($clone->headerNames[$lower])) {
            $existing = $clone->headerNames[$lower];
            $clone->headers[$existing] = [...$clone->headers[$existing], ...$values];

            return $clone;
        }

        $clone->headerNames[$lower] = $name;
        $clone->headers[$name] = $values;

        return $clone;
    }

    #[\NoDiscard('withoutHeader() returns a new message; the receiver is unchanged')]
    public function withoutHeader(string $name): static
    {
        $lower = strtolower($name);

        if (!isset($this->headerNames[$lower])) {
            return $this;
        }

        $clone = clone $this;
        unset($clone->headers[$clone->headerNames[$lower]], $clone->headerNames[$lower]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->stream ??= Stream::of('');
    }

    #[\NoDiscard('withBody() returns a new message; the receiver is unchanged')]
    public function withBody(StreamInterface $body): static
    {
        if ($this->stream === $body) {
            return $this;
        }

        $clone = clone $this;
        $clone->stream = $body;

        return $clone;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function setHeaders(array $headers): void
    {
        $this->headers = [];
        $this->headerNames = [];

        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $this->assertHeaderName($name);

            $values = $this->normaliseHeaderValue($name, $value);
            $lower = strtolower($name);

            if (isset($this->headerNames[$lower])) {
                $existing = $this->headerNames[$lower];
                $this->headers[$existing] = [...$this->headers[$existing], ...$values];

                continue;
            }

            $this->headerNames[$lower] = $name;
            $this->headers[$name] = $values;
        }
    }

    /**
     * @return list<string>
     */
    private function normaliseHeaderValue(string $name, mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];

        if ($values === []) {
            throw new InvalidArgumentException("Header [{$name}] must have at least one value.");
        }

        return array_map(
            function (mixed $item) use ($name): string {
                if (is_int($item) || is_float($item)) {
                    $item = (string) $item;
                }

                if (!is_string($item)) {
                    throw new InvalidArgumentException(
                        "Header [{$name}] values must be strings, " . get_debug_type($item) . ' given.',
                    );
                }

                // RFC 7230 field-value: no CR, LF or NUL anywhere. This check is
                // what stops header/response splitting attacks.
                if (preg_match('/[\r\n\0]/', $item) === 1) {
                    throw new InvalidArgumentException(
                        "Header [{$name}] value contains a CR, LF or NUL byte.",
                    );
                }

                return trim($item, " \t");
            },
            $values,
        );
    }

    private function assertHeaderName(string $name): void
    {
        // RFC 7230 token.
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a valid header name.");
        }
    }
}

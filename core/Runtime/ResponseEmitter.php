<?php

declare(strict_types=1);

namespace Kayra\Runtime;

use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Writes a PSR-7 response to a standard PHP SAPI.
 */
final class ResponseEmitter
{
    /**
     * @param int $chunkSize Bytes per write when streaming a body.
     */
    public function __construct(private readonly int $chunkSize = 8192)
    {
    }

    public function emit(ResponseInterface $response): void
    {
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    private function emitHeaders(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(
                "Cannot send headers: output already started at {$file}:{$line}.",
            );
        }

        // Clear anything PHP queued (a session cookie, for example) so the
        // response object is the single source of truth.
        header_remove();

        $lines = $response instanceof Response
            ? $response->headerLines()
            : $this->genericHeaderLines($response);

        $seen = [];

        foreach ($lines as [$name, $value]) {
            $key = strtolower($name);

            // First occurrence replaces, later ones append.
            header("{$name}: {$value}", !isset($seen[$key]));
            $seen[$key] = true;
        }

        // The status line must be sent after the headers so PHP does not reset it.
        http_response_code($response->getStatusCode());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function genericHeaderLines(ResponseInterface $response): array
    {
        $lines = [];

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = [$name, $value];
            }
        }

        return $lines;
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable()) {
            echo (string) $body;

            return;
        }

        // Stream rather than materialising the whole body: a file download must
        // not have to fit in memory.
        while (!$body->eof()) {
            $chunk = $body->read($this->chunkSize);

            if ($chunk === '') {
                break;
            }

            echo $chunk;
        }
    }
}

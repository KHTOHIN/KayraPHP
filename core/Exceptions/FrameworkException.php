<?php

namespace Kayra\Exceptions;

class FrameworkException extends \Exception
{
    public function __construct(string $message, int $code = 500, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        // Log via container('logger')
        if (function_exists('container')) {
            container('logger')->error($message, ['code' => $code]);
        }
    }
}
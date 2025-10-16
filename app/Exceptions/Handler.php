<?php

namespace App\Exceptions;

use Throwable;
use Kayra\Http\Response;
use Kayra\Exceptions\FrameworkException;

class Handler
{
    public function report(Throwable $e): void
    {
        container('logger')->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    }

    public function render(Throwable $e): Response
    {
        if ($e instanceof FrameworkException) {
            return Response::create(500, [], 'Framework Error: ' . $e->getMessage());
        }
        return Response::create(500, [], 'Server Error');
    }
}
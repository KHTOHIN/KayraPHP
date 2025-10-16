<?php

namespace App\Middlewares;

use Kayra\Http\Request;
use Kayra\Http\Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Stub: Check auth header
        if (!$request->hasHeader('Authorization')) {
            return Response::create(401, [], 'Unauthorized');
        }
        return $handler->handle($request);
    }
}
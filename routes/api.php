<?php

return [
    'GET' => [
        '/api/ping' => fn($req) => \Kayra\Http\Response::json(['pong' => true]),
    ],
];
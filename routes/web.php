<?php

return [
    'GET' => [
        '/' => 'App\\Controllers\\HomeController@index',
        '/about' => fn($req) => \Kayra\Http\Response::create(200, [], 'About Page'),
    ],
    'POST' => [
        '/contact' => 'App\\Controllers\\ContactController@submit',
    ],
];
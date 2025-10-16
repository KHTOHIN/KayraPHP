<?php

namespace App\Controllers;

use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('home', ['title' => 'Welcome to KayraPHP Framework']);
    }

    public function hello(Request $request, string $name): Response
    {
        return $this->json(['message' => "Hello, {$name}!"]);
    }
}
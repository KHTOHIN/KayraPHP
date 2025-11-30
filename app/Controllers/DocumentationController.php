<?php

namespace App\Controllers;

use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;

class DocumentationController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('documentation', ['title' => 'KayraPHP Documentation']);
    }
}
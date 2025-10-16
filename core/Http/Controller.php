<?php

namespace Kayra\Http;

abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Return JSON response quickly.
     */
    protected function json(array $data, int $status = 200): Response
    {
        return $this->response->json($data, $status);
    }

    /**
     * Render a view file from resources/views.
     */
    protected function view(string $view, array $data = []): Response
    {
        return $this->response->view($view, $data);
    }

    /**
     * Example default action for testing.
     */
    public function index(Request $request): Response
    {
        return $this->view('home', ['title' => 'Welcome Home']);
    }
}
<?php

namespace {{namespace}};

use PHPNomad\Http\Interfaces\Request;
use PHPNomad\Http\Interfaces\Response;
use PHPNomad\Rest\Interfaces\Controller;

class {{name}} implements Controller
{
    public function __construct(
        protected Response $response,
    ) {
    }

    public function getMethod(): string
    {
        return '{{method}}';
    }

    public function getEndpoint(): string
    {
        return '{{endpoint}}';
    }

    public function getResponse(Request $request): Response
    {
        // TODO: Implement controller logic
        return $this->response;
    }
}

<?php

namespace Mashbo\Components\Psr7ServerRequestFactory;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Convert a client-side RequestInterface to one fit for server-side consumption.
 */
interface ServerRequestFactory
{
    /**
     * @return ServerRequestInterface
     */
    public function convertToServerRequest(RequestInterface $request);
}
<?php

namespace Mashbo\Components\Psr7ServerRequestFactory;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\UploadedFile;

/**
 * Create a ServerRequestInterface to include the normal superglobals
 * that would normally be available server-side, like $_SERVER, $_FILES,
 * $_COOKIE, etc..
 *
 * Creation of superglobals inspired by HttpFoundation's request creation.
 * @see Symfony\Component\HttpFoundation\Request::create
 */
class SimulatedServerRequestFactory implements ServerRequestFactory
{
    /**
     * @var array
     */
    private $defaultServerParams;

    public function __construct(array $defaultServerParams = [])
    {
        $this->defaultServerParams = $defaultServerParams;
    }

    /**
     * @return ServerRequestInterface
     */
    public function convertToServerRequest(RequestInterface $request)
    {
        $parsedBody = null;
        $uploadedFiles = [];

        if ($request->getHeaderLine('Content-type') == 'application/x-www-form-urlencoded') {
            $parsedBody = [];
            parse_str((string) $request->getBody(), $parsedBody);
        } elseif (false !== stripos($request->getHeaderLine('Content-type'), 'multipart/form-data')) {
            $this->createServerUploadedFiles($request, $uploadedFiles, $parsedBody);
        }

        $serverRequest = new ServerRequest(
            $this->createServerParams($request),
            $uploadedFiles,
            $request->getUri(),
            $request->getMethod(),
            $request->getBody(),
            $request->getHeaders(),
            $this->createServerRequestCookies($request),
            $this->createQueryParams($request),
            $parsedBody,
            $request->getProtocolVersion()
        );

        return $serverRequest;
    }

    private function createServerParams(RequestInterface $request)
    {
        $server = array_replace(array(
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'localhost',
            'HTTP_USER_AGENT' => 'Symfony/3.X',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'HTTP_ACCEPT_LANGUAGE' => 'en-us,en;q=0.5',
            'HTTP_ACCEPT_CHARSET' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => '',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_TIME' => time(),
        ), $this->defaultServerParams);

        $server['PATH_INFO'] = '';
        $server['REQUEST_METHOD'] = strtoupper($request->getMethod());

        $components = parse_url($request->getUri()->__toString());
        if (isset($components['host'])) {
            $server['SERVER_NAME'] = $components['host'];
            $server['HTTP_HOST'] = $components['host'];
        }

        if (isset($components['scheme'])) {
            if ('https' === $components['scheme']) {
                $server['HTTPS'] = 'on';
                $server['SERVER_PORT'] = 443;
            } else {
                unset($server['HTTPS']);
                $server['SERVER_PORT'] = 80;
            }
        }

        if (isset($components['port'])) {
            $server['SERVER_PORT'] = $components['port'];
            $server['HTTP_HOST'] = $server['HTTP_HOST'].':'.$components['port'];
        }

        if (isset($components['user'])) {
            $server['PHP_AUTH_USER'] = $components['user'];
        }

        if (isset($components['pass'])) {
            $server['PHP_AUTH_PW'] = $components['pass'];
        }

        if (!isset($components['path'])) {
            $components['path'] = '/';
        }

        if ($request->hasHeader('Authorization')) {
            $authHeader = $request->getHeader('Authorization')[0];
            if (preg_match('/^Basic (.*)$/', $authHeader)) {

                $creds = explode(':', base64_decode(substr($authHeader, 6)));
                if (count($creds) !== 2) {
                    throw new \InvalidArgumentException("Expected basic auth header to have base64 encoded username:password");
                }

                $server['PHP_AUTH_USER']    = $creds[0];
                $server['PHP_AUTH_PW']      = $creds[1];
                $server['AUTH_TYPE']        = 'Basic';

            } elseif (preg_match('/^Bearer (.*)$/', $authHeader)) {
                $server['HTTP_AUTHORIZATION'] = $authHeader;
            }
            else {
                throw new \LogicException("Authorization header not yet supported: $authHeader");
            }
        }

        switch (strtoupper($request->getMethod())) {
            case 'POST':
            case 'PUT':
            case 'DELETE':
                if (!isset($server['CONTENT_TYPE'])) {
                    $server['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
                }
            // no break
            case 'PATCH':
                $query = array();
                break;
            default:
                break;
        }

        $queryString = '';
        if (isset($components['query'])) {
            parse_str(html_entity_decode($components['query']), $qs);

            $query = $qs;
            $queryString = http_build_query($query, '', '&');
        }

        $server['REQUEST_URI'] = $components['path'].('' !== $queryString ? '?'.$queryString : '');
        $server['QUERY_STRING'] = $queryString;

        return $server;
    }

    private function createServerUploadedFiles(RequestInterface $request, &$files, &$params)
    {
        // Adapted from http://stackoverflow.com/a/9469615/525649
        $filesToReturn = [];

        // Fetch content and determine boundary
        $raw_data = (string) $request->getBody();
        $boundary = substr($raw_data, 0, strpos($raw_data, "\r\n"));

        // Fetch each part
        $parts = array_slice(explode($boundary, $raw_data), 1);
        $data = array();

        foreach ($parts as $part) {
            // If this is the last part, break
            if ($part == "--\r\n") break;

            // Separate content from headers
            $part = ltrim($part, "\r\n");
            list($raw_headers, $body) = explode("\r\n\r\n", $part, 2);

            // Parse the headers list
            $raw_headers = explode("\r\n", $raw_headers);
            $headers = array();
            foreach ($raw_headers as $header) {
                list($name, $value) = explode(':', $header);
                $headers[strtolower($name)] = ltrim($value, ' ');
            }

            // Parse the Content-Disposition to get the field name, etc.
            if (isset($headers['content-disposition'])) {
                $filename = null;
                preg_match(
                    '/^(.+); *name="([^"]+)"(; *filename="([^"]+)")?/',
                    $headers['content-disposition'],
                    $matches
                );
                list(, $type, $name) = $matches;
                isset($matches[4]) and $filename = $matches[4];

                if (array_key_exists('content-type', $headers) && $headers['content-type'] != 'text/plain') {
                    $fileStream = fopen('php://temp', 'w+');
                    fwrite($fileStream, $body);
                    fseek($fileStream, 0);

                    $filesToReturn[$name] = new UploadedFile($fileStream, strlen($body), UPLOAD_ERR_OK, $filename, $headers['content-type']);
                } else {
                    $data[$name] = substr($body, 0, strlen($body) - 2);
                }
            }

        }

        $files  = $filesToReturn;
        $params = $data;
    }

    private function createQueryParams(RequestInterface $request)
    {
        $query = $request->getUri()->getQuery();
        if (!$query) {
            return [];
        }
        
        parse_str($query, $params);
        return $params;
    }

    private function createServerRequestCookies(RequestInterface $request)
    {
        return [];
    }
}
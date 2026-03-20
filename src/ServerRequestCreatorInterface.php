<?php

declare (strict_types=1);
namespace Nyholm\Psr7Server;

use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 * @author Martijn van der Ven <martijn@vanderven.se>
 */
interface Server_Request_Creator_Interface
{
    /**
     * Create a new server request from the current environment variables.
     * Defaults to a GET request to minimise the risk of an \InvalidArgumentException.
     * Includes the current request headers as supplied by the server through `getallheaders()`.
     * If `getallheaders()` is unavailable on the current server it will fallback to its own `getHeadersFromServer()` method.
     * Defaults to php://input for the request body.
     *
     * @throws \InvalidArgumentException if no valid method or URI can be determined
     */
    public function from_globals(): Server_Request_Interface;
    /**
     * Create a new server request from a set of arrays.
     *
     * @param array                                $server  typically $_SERVER or similar structure
     * @param array                                $headers typically the output of getallheaders() or similar structure
     * @param array                                $cookie  typically $_COOKIE or similar structure
     * @param array                                $get     typically $_GET or similar structure
     * @param array|null                           $post    typically $_POST or similar structure, represents parsed request body
     * @param array                                $files   typically $_FILES or similar structure
     * @param StreamInterface|resource|string|null $body    Typically stdIn
     *
     * @throws \InvalidArgumentException if no valid method or URI can be determined
     */
    public function from_arrays(array $server, array $headers = [], array $cookie = [], array $get = [], ?array $post = null, array $files = [], $body = null): Server_Request_Interface;
    /**
     * Get parsed headers from ($_SERVER) array.
     *
     * @param array $server typically $_SERVER or similar structure
     */
    public static function get_headers_from_server(array $server): array;
}
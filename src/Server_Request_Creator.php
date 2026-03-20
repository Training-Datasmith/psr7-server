<?php

declare (strict_types=1);
namespace Nyholm\Psr7Server;

use Psr\Http\Message\Server_Request_Factory_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Message\Stream_Factory_Interface;
use Psr\Http\Message\Stream_Interface;
use Psr\Http\Message\Uploaded_File_Factory_Interface;
use Psr\Http\Message\Uploaded_File_Interface;
use Psr\Http\Message\Uri_Factory_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 * @author Martijn van der Ven <martijn@vanderven.se>
 */
final class Server_Request_Creator implements Server_Request_Creator_Interface
{
    private $server_request_factory;
    private $uri_factory;
    private $uploaded_file_factory;
    private $stream_factory;
    public function __construct(Server_Request_Factory_Interface $server_request_factory, Uri_Factory_Interface $uri_factory, Uploaded_File_Factory_Interface $uploaded_file_factory, Stream_Factory_Interface $stream_factory)
    {
        $this->server_request_factory = $server_request_factory;
        $this->uri_factory = $uri_factory;
        $this->uploaded_file_factory = $uploaded_file_factory;
        $this->stream_factory = $stream_factory;
    }
    /**
     * {@inheritdoc}
     */
    public function from_globals(): Server_Request_Interface
    {
        $server = $_SERVER;
        if (false === isset($server['REQUEST_METHOD'])) {
            $server['REQUEST_METHOD'] = 'GET';
        }
        $headers = \function_exists('getallheaders') ? getallheaders() : static::get_headers_from_server($_SERVER);
        $post = null;
        if ('POST' === $this->get_method_from_env($server)) {
            foreach ($headers as $header_name => $header_value) {
                if (true === \is_int($header_name)) {
                    continue;
                }
                if ('content-type' !== \strtolower($header_name)) {
                    continue;
                }
                if (\in_array(\strtolower(\trim(\explode(';', $header_value, 2)[0])), ['application/x-www-form-urlencoded', 'multipart/form-data'])) {
                    $post = $_POST;
                    break;
                }
            }
        }
        return $this->from_arrays($server, $headers, $_COOKIE, $_GET, $post, $_FILES, \fopen('php://input', 'r') ?: null);
    }
    /**
     * {@inheritdoc}
     */
    public function from_arrays(array $server, array $headers = [], array $cookie = [], array $get = [], ?array $post = null, array $files = [], $body = null): Server_Request_Interface
    {
        $method = $this->get_method_from_env($server);
        $uri = $this->get_uri_from_env_with_http($server);
        $protocol = isset($server['SERVER_PROTOCOL']) ? \str_replace('HTTP/', '', $server['SERVER_PROTOCOL']) : '1.1';
        $server_request = $this->server_request_factory->create_server_request($method, $uri, $server);
        foreach ($headers as $name => $value) {
            // Because PHP automatically casts array keys set with numeric strings to integers, we have to make sure
            // that numeric headers will not be sent along as integers, as withAddedHeader can only accept strings.
            if (\is_int($name)) {
                $name = (string) $name;
            }
            $server_request = $server_request->with_added_header($name, $value);
        }
        $server_request = $server_request->with_protocol_version($protocol)->with_cookie_params($cookie)->with_query_params($get)->with_parsed_body($post)->with_uploaded_files($this->normalize_files($files));
        if (null === $body) {
            return $server_request;
        }
        if (\is_resource($body)) {
            $body = $this->stream_factory->create_stream_from_resource($body);
        } elseif (\is_string($body)) {
            $body = $this->stream_factory->create_stream($body);
        } elseif (!$body instanceof Stream_Interface) {
            throw new \InvalidArgumentException('The $body parameter to ServerRequestCreator::fromArrays must be string, resource or StreamInterface');
        }
        return $server_request->with_body($body);
    }
    /**
     * Implementation from Laminas\Diactoros\marshalHeadersFromSapi().
     */
    public static function get_headers_from_server(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            // Apache prefixes environment variables with REDIRECT_
            // if they are added by rewrite rules
            if (0 === \strpos($key, 'REDIRECT_')) {
                $key = \substr($key, 9);
                // We will not overwrite existing variables with the
                // prefixed versions, though
                if (\array_key_exists($key, $server)) {
                    continue;
                }
            }
            if ($value && 0 === \strpos($key, 'HTTP_')) {
                $name = \strtr(\strtolower(\substr($key, 5)), '_', '-');
                $headers[$name] = $value;
                continue;
            }
            if ($value && 0 === \strpos($key, 'CONTENT_')) {
                $name = 'content-' . \strtolower(\substr($key, 8));
                $headers[$name] = $value;
                continue;
            }
        }
        return $headers;
    }
    private function get_method_from_env(array $environment): string
    {
        if (false === isset($environment['REQUEST_METHOD'])) {
            throw new \InvalidArgumentException('Cannot determine HTTP method');
        }
        return $environment['REQUEST_METHOD'];
    }
    private function get_uri_from_env_with_http(array $environment): Uri_Interface
    {
        $uri = $this->create_uri_from_array($environment);
        if (empty($uri->get_scheme())) {
            return $uri->with_scheme('http');
        }
        return $uri;
    }
    /**
     * Return an UploadedFile instance array.
     *
     * @param array $files A array which respect $_FILES structure
     *
     * @return UploadedFileInterface[]
     *
     * @throws \InvalidArgumentException for unrecognized values
     */
    private function normalize_files(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if ($value instanceof Uploaded_File_Interface) {
                $normalized[$key] = $value;
            } elseif (\is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->create_uploaded_file_from_spec($value);
            } elseif (\is_array($value)) {
                $normalized[$key] = $this->normalize_files($value);
            } else {
                throw new \InvalidArgumentException('Invalid value in files specification');
            }
        }
        return $normalized;
    }
    /**
     * Create and return an UploadedFile instance from a $_FILES specification.
     *
     * If the specification represents an array of values, this method will
     * delegate to normalizeNestedFileSpec() and return that return value.
     *
     * @param array $value $_FILES struct
     *
     * @return array|UploadedFileInterface
     */
    private function create_uploaded_file_from_spec(array $value)
    {
        if (\is_array($value['tmp_name'])) {
            return $this->normalize_nested_file_spec($value);
        }
        if (UPLOAD_ERR_OK !== $value['error']) {
            $stream = $this->stream_factory->create_stream();
        } else {
            try {
                $stream = $this->stream_factory->create_stream_from_file($value['tmp_name']);
            } catch (\RuntimeException $e) {
                $stream = $this->stream_factory->create_stream();
            }
        }
        return $this->uploaded_file_factory->create_uploaded_file($stream, (int) $value['size'], (int) $value['error'], $value['name'], $value['type']);
    }
    /**
     * Normalize an array of file specifications.
     *
     * Loops through all nested files and returns a normalized array of
     * UploadedFileInterface instances.
     *
     * @return UploadedFileInterface[]
     */
    private function normalize_nested_file_spec(array $files = []): array
    {
        $normalized_files = [];
        foreach (\array_keys($files['tmp_name']) as $key) {
            $spec = ['tmp_name' => $files['tmp_name'][$key], 'size' => $files['size'][$key], 'error' => $files['error'][$key], 'name' => $files['name'][$key], 'type' => $files['type'][$key]];
            $normalized_files[$key] = $this->create_uploaded_file_from_spec($spec);
        }
        return $normalized_files;
    }
    /**
     * Create a new uri from server variable.
     *
     * @param array $server typically $_SERVER or similar structure
     */
    private function create_uri_from_array(array $server): Uri_Interface
    {
        $uri = $this->uri_factory->create_uri('');
        if (isset($server['HTTP_X_FORWARDED_PROTO'])) {
            $uri = $uri->with_scheme($server['HTTP_X_FORWARDED_PROTO']);
        } else {
            if (isset($server['REQUEST_SCHEME'])) {
                $uri = $uri->with_scheme($server['REQUEST_SCHEME']);
            } elseif (isset($server['HTTPS'])) {
                $uri = $uri->with_scheme('on' === $server['HTTPS'] ? 'https' : 'http');
            }
            if (isset($server['SERVER_PORT'])) {
                $uri = $uri->with_port($server['SERVER_PORT']);
            }
        }
        if (isset($server['HTTP_HOST'])) {
            if (1 === \preg_match('/^(.+)\:(\d+)$/', $server['HTTP_HOST'], $matches)) {
                $uri = $uri->with_host($matches[1])->with_port($matches[2]);
            } else {
                $uri = $uri->with_host($server['HTTP_HOST']);
            }
        } elseif (isset($server['SERVER_NAME'])) {
            $uri = $uri->with_host($server['SERVER_NAME']);
        }
        if (isset($server['REQUEST_URI'])) {
            $uri = $uri->with_path(\current(\explode('?', $server['REQUEST_URI'])));
        }
        if (isset($server['QUERY_STRING'])) {
            return $uri->with_query($server['QUERY_STRING']);
        }
        return $uri;
    }
}
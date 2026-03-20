# Architecture: psr7-server

## Purpose

A PSR-7 `ServerRequest` creator (Nyholm PSR7 Server) that populates a `ServerRequestInterface` from PHP superglobals (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`). A lightweight alternative to framework-specific request factories.

## Directory Structure

```
src/
  Server_Request_Creator.php           - Creates a PSR-7 ServerRequestInterface from PHP superglobals
  Server_Request_Creator_Interface.php - Contract for server request creators
```

## Key Design Decisions

- **PSR-17 factory injection**: `Server_Request_Creator` requires PSR-17 factories (`ServerRequestFactoryInterface`, `UriFactoryInterface`, `StreamFactoryInterface`, `UploadedFileFactoryInterface`) to be injected, making it PSR-7-library-agnostic.
- **Superglobal isolation**: The creator reads superglobals at call time rather than storing them as state, making it safe in long-running SAPI environments.
- **Minimal surface**: Only two classes — the implementation and its interface. No framework coupling.

## Extension Points

- Implement `Server_Request_Creator_Interface` to customise how superglobals are normalised (e.g., to add header parsing for reverse-proxy environments).

## Dependency Flow

```
Server_Request_Creator::fromGlobals()
  └─> reads $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
  └─> ServerRequestFactoryInterface::createServerRequest()
  └─> UriFactoryInterface::createUri()
  └─> StreamFactoryInterface::createStreamFromFile()
  └─> UploadedFileFactoryInterface::createUploadedFile()
  └─> returns ServerRequestInterface
```

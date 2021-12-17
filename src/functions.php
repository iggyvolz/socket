<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;

/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param BindContext|null $context Context options for listening.
 *
 * @return ResourceSocketServer
 *
 * @throws SocketException If binding to the specified URI failed.
 * @throws \Error If an invalid scheme is given.
 */
function listen(string $uri, ?BindContext $context = null): ResourceSocketServer
{
    $context = $context ?? new BindContext;

    $scheme = \strstr($uri, '://', true);

    if ($scheme === false) {
        $uri = 'tcp://' . $uri;
    } elseif (!\in_array($scheme, ['tcp', 'unix'])) {
        throw new \Error('Only tcp and unix schemes allowed for server creation');
    }

    $streamContext = \stream_context_create($context->toStreamContextArray());

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $streamContext);

    if (!$server || $errno) {
        throw new SocketException(\sprintf('Could not create server %s: [Error: #%d] %s', $uri, $errno, $errstr), $errno);
    }

    return new ResourceSocketServer($server, $context->getChunkSize());
}

/**
 * Create a new Datagram (UDP server) on the specified server address.
 *
 * @param string $uri URI in scheme://host:port format. UDP is assumed if no scheme is present.
 * @param BindContext|null $context Context options for listening.
 *
 * @return ResourceDatagramSocket
 *
 * @throws SocketException If binding to the specified URI failed.
 * @throws \Error If an invalid scheme is given.
 */
function bind(string $uri, ?BindContext $context = null): ResourceDatagramSocket
{
    $context = $context ?? new BindContext;

    $scheme = \strstr($uri, '://', true);

    if ($scheme === false) {
        $uri = 'udp://' . $uri;
    } elseif ($scheme !== 'udp') {
        throw new \Error('Only udp scheme allowed for datagram creation');
    }

    $streamContext = \stream_context_create($context->toStreamContextArray());

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $streamContext);

    if (!$server || $errno) {
        throw new SocketException(
            \sprintf('Could not create datagram %s: [Error: #%d] %s', $uri, $errno, $errstr),
            $errno
        );
    }

    return new ResourceDatagramSocket($server, $context->getChunkSize());
}

/**
 * Set or access the global socket Connector instance.
 *
 * @param Connector|null $connector
 *
 * @return Connector
 */
function connector(?Connector $connector = null): Connector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    return $map[$driver] ??= new DnsConnector();
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null $context Socket connect context to use when connecting.
 * @param Cancellation|null $cancellation
 *
 * @return EncryptableSocket
 *
 * @throws ConnectException
 * @throws CancelledException
 */
function connect(string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): EncryptableSocket
{
    return connector()->connect($uri, $context, $cancellation);
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @return array{ResourceSocket, ResourceSocket} Pair of socket resources.
 *
 * @throws SocketException If creating the sockets fails.
 */
function createPair(int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE): array
{
    try {
        \set_error_handler(static function (int $errno, string $errstr): void {
            throw new SocketException(\sprintf('Failed to create socket pair.  Errno: %d; %s', $errno, $errstr));
        });

        $sockets = \stream_socket_pair(
            \PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($sockets === false) {
            throw new SocketException('Failed to create socket pair.');
        }
    } finally {
        \restore_error_handler();
    }

    return [
        ResourceSocket::fromClientSocket($sockets[0], chunkSize: $chunkSize),
        ResourceSocket::fromClientSocket($sockets[1], chunkSize: $chunkSize),
    ];
}

/**
 * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
 * @return bool
 */
function hasTlsAlpnSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10002000;
}

function hasTlsSecurityLevelSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10100000;
}

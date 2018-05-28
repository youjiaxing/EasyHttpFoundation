<?php
namespace EasyHttpFoundation;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;


/**
 * Returns the string representation of an HTTP message.
 *
 * @param MessageInterface $message Message to convert to a string.
 *
 * @return string
 */
function str(MessageInterface $message)
{
    if ($message instanceof RequestInterface) {
        $msg = $message->getMethod().' '.$message->getRequestTarget().' HTTP/'.$message->getProtocolVersion();
        if (!$message->hasHeader('host')) {
            $msg .= "\r\nHost: ".$message->getUri()->getHost();
        }
    } elseif ($message instanceof ResponseInterface) {
        $msg = 'HTTP/'.$message->getProtocolVersion().' '.$message->getStatusCode().' '.$message->getReasonPhrase();
    } else {
        throw new \InvalidArgumentException("Unknown message type");
    }

    foreach ($message->getHeaders() as $name => $value) {
        $msg .= "\r\n{$name}: ".(strtolower($name) === 'cookie' ? implode('; ', $value) : implode(', ', $value));
    }

    $msg .= "\r\n\r\n".$message->getBody();
    return $msg;
}

/**
 * Safely opens a PHP stream resource using a filename.
 *
 * When fopen fails, PHP normally raises a warning. This function adds an
 * error handler that checks for errors and throws an exception instead.
 *
 * @param string $filename File to open
 * @param string $mode     Mode used to open the file
 *
 * @return resource
 * @throws \RuntimeException if the file cannot be opened
 */
function try_fopen($filename, $mode)
{
    $ex = null;
    set_error_handler(function ($errno, $errstr) use ($filename, $mode, &$ex) {
        $ex = new \RuntimeException(sprintf(
            'Unable to open %s using mode %s: #%s %s',
            $filename,
            $mode,
            $errno,
            $errstr
        ));
    });

    $handle = fopen($filename, $mode);
    restore_error_handler();

    if ($ex) {
        /* @var \RuntimeException $ex */
        throw $ex;
    }

    return $handle;
}

/**
 * Returns a UriInterface for the given value.
 *
 * This function accepts a string or {@see Psr\Http\Message\UriInterface} and
 * returns a UriInterface for the given value. If the value is already a
 * `UriInterface`, it is returned as-is.
 *
 * @param string|UriInterface $uri
 *
 * @return UriInterface
 * @throws \InvalidArgumentException
 */
function uri_for($uri)
{
    if ($uri instanceof UriInterface) {
        return $uri;
    } elseif (is_string($uri)) {
        return new Uri($uri);
    }

    throw new \InvalidArgumentException('URI must be a string or UriInterface');
}


/**
 * Create a new stream based on the input type.
 *
 * Options is an associative array that can contain the following keys:
 * - metadata: Array of custom metadata.
 * - size: Size of the stream.
 *
 * @param resource|string|null|int|float|bool|StreamInterface|callable|\Iterator $resource Entity body data
 * @param array                                                                  $options  Additional options
 *
 * @return StreamInterface
 * @throws \InvalidArgumentException if the $resource arg is not valid.
 */
function stream_for($resource = '', array $options = [])
{
    // 若是标量则创建一个临时流, 将内容写入该临时流中并重置游标位置
    if (is_scalar($resource)) {
        $stream = fopen('php://temp', 'r+');
        if ($resource !== '') {
            fwrite($stream, $resource);
            fseek($stream, 0);
        }
        return new Stream($stream, $options);
    }

    switch (gettype($resource)) {
        case 'resource':
            return new Stream($resource, $options);
            break;

        case 'object':
            if ($resource instanceof StreamInterface) {
                return $resource;
            } elseif ($resource instanceof \Iterator) {
                return new PumpStream(function () use ($resource) {
                    if (!$resource->valid()) {
                        return false;
                    }
                    $result = $resource->current();
                    $resource->next();
                    return $result;
                }, $options);
            } elseif (method_exists($resource, '__toString')) {
                return stream_for((string)$resource, $options);
            }
            break;

        case 'NULL':
            return new Stream(fopen('php://temp', 'r+'), $options);
    }

    if (is_callable($resource)) {
        return new PumpStream($resource, $options);
    }

    throw new \InvalidArgumentException("Resource is invalid");
}

/**
 * Copy the contents of a stream into another stream until the given number
 * of bytes have been read.
 *
 * @param StreamInterface $source Stream to read from
 * @param StreamInterface $dest   Stream to write to
 * @param int             $maxLen Maximum number of bytes to read. Pass -1
 *                                to read the entire stream.
 *
 * @throws \RuntimeException on error.
 */
function copy_to_stream(StreamInterface $source, StreamInterface $dest, $maxLen = -1)
{
    $bufferSize = 8192;

    if ($maxLen === -1) {
        while (!$source->eof()) {
            if (!$dest->write($source->read($bufferSize))) {
                break;
            }
        }
    } else {
        $remaining = $maxLen;
        while ($remaining > 0 && !$source->eof()) {
            $buf = $source->read(min($bufferSize, $remaining));
            if (strlen($buf) === 0) {
                break;
            }
            $remaining -= $buf;
            $dest->write($buf);
        }
    }
}


/**
 * Copy the contents of a stream into a string until the given number of
 * bytes have been read.
 *
 * @param StreamInterface $stream Stream to read
 * @param int             $maxLen Maximum number of bytes to read. Pass -1
 *                                to read the entire stream.
 * @return string
 * @throws \RuntimeException on error.
 */
function copy_to_string(StreamInterface $stream, $maxLen = -1)
{
    $buffer = '';

    // 读取全部
    if ($maxLen === -1) {
        while (!$stream->eof()) {
            $buf = $stream->read(1048576);
            $buffer .= $buf;
        }
        return $buffer;
    }

    // 读取指定长度
    $remain = $maxLen;
    while ($remain > 0 && !$stream->eof()) {
        $buf = $stream->read(min(1048576, $remain));
        $remain -= strlen($buf);
        $buffer .= $buf;
    }
    return $buffer;
}
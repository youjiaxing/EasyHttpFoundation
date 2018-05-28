<?php
namespace EasyHttpFoundation;

use Psr\Http\Message\StreamInterface;

trait MessageTrait
{
    protected $protocolVersion = '1.1';

    /**
     * @var array 所有注册的头的映射数组, raw head name => 值数组
     */
    protected $headers = [];

    /**
     * @var array 小写head name => raw head name 的映射数组
     */
    protected $headerNames = [];

    /* @var StreamInterface */
    protected $stream;


    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * The string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @return string HTTP protocol version.
     */
    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     *
     * The version string MUST contain only the HTTP version number (e.g.,
     * "1.1", "1.0").
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new protocol version.
     *
     * @param string $version HTTP protocol version
     * @return static
     */
    public function withProtocolVersion($version)
    {
        if ($version === $this->protocolVersion) {
            return $this;
        }

        $msg = clone $this;
        $msg->protocolVersion = $version;
        return $msg;
    }

    /**
     * Retrieves all message header values.
     *
     * The keys represent the header name as it will be sent over the wire, and
     * each value is an array of strings associated with the header.
     *
     *     // Represent the headers as a string
     *     foreach ($message->getHeaders() as $name => $values) {
     *         echo $name . ": " . implode(", ", $values);
     *     }
     *
     *     // Emit headers iteratively:
     *     foreach ($message->getHeaders() as $name => $values) {
     *         foreach ($values as $value) {
     *             header(sprintf('%s: %s', $name, $value), false);
     *         }
     *     }
     *
     * While header names are not case-sensitive, getHeaders() will preserve the
     * exact case in which headers were originally specified.
     *
     * @return string[][] Returns an associative array of the message's headers. Each
     *     key MUST be a header name, and each value MUST be an array of strings
     *     for that header.
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name Case-insensitive header field name.
     * @return bool Returns true if any header names match the given header
     *     name using a case-insensitive string comparison. Returns false if
     *     no matching header name is found in the message.
     */
    public function hasHeader($name)
    {
        return array_key_exists(strtolower($name), $this->headerNames);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * If the header does not appear in the message, this method MUST return an
     * empty array.
     *
     * @param string $name Case-insensitive header field name.
     * @return string[] An array of string values as provided for the given
     *    header. If the header does not appear in the message, this method MUST
     *    return an empty array.
     */
    public function getHeader($name)
    {
        $headerName = $this->headerNames[strtolower($name)];
        return $this->hasHeader($name) ? $this->headers[$headerName] : [];
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all of the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * NOTE: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use getHeader() instead
     * and supply your own delimiter when concatenating.
     *
     * If the header does not appear in the message, this method MUST return
     * an empty string.
     *
     * @param string $name Case-insensitive header field name.
     * @return string A string of values as provided for the given header
     *    concatenated together using a comma. If the header does not appear in
     *    the message, this method MUST return an empty string.
     */
    public function getHeaderLine($name)
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new and/or updated header and value.
     *
     * @param string $name Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws \InvalidArgumentException for invalid header names or values.
     */
    public function withHeader($name, $value)
    {
        if (!is_string($name) || empty($name)) {
            throw new \InvalidArgumentException("header name must be non-empty string");
        }

        if (is_string($value)) {
            $value = [$value];
        } elseif (empty($value) || !is_array($value)) {
            throw new \InvalidArgumentException("header value must be non-empty strings");
        }

        $value = array_values($this->trimHeaderValues($value));
        foreach ($value as $v) {
            if (!is_string($v)) {
                throw new \InvalidArgumentException("header value must be non-empty strings");
            }
        }

        $nameNormalize = strtolower($name);
        $msg = clone $this;

        if ($this->hasHeader($name)) {
            unset($msg->headers[$msg->headerNames[$nameNormalize]]);
        }

        $msg->headerNames[$nameNormalize] = $name;
        $msg->headers[$name] = $value;
        return $msg;
    }

    private function setHeaders(array $headers)
    {
        $this->headerNames = $this->headers = [];
        foreach ($headers as $headerName => $values) {
            $values = $this->trimHeaderValues((array)$values);
            $normalized = strtolower($headerName);
            if (isset($this->headerNames[$normalized])) {
                $headerName = $this->headerNames[$normalized];
                $this->headers[$headerName] = array_merge($this->headers[$headerName], $values);
            } else {
                $this->headerNames[$normalized] = $headerName;
                $this->headers[$headerName] = $values;
            }
        }
    }

    /**
     *
     * @param string[] $values
     * @return string[]
     */
    private function trimHeaderValues(array $values)
    {
        return array_map(function ($value) {
            return trim($value, " \t");
        }, $values);
    }

    /**
     * Return an instance with the specified header appended with the given value.
     *
     * Existing values for the specified header will be maintained. The new
     * value(s) will be appended to the existing list. If the header did not
     * exist previously, it will be added.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new header and/or value.
     *
     * @param string $name Case-insensitive header field name to add.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws \InvalidArgumentException for invalid header names or values.
     */
    public function withAddedHeader($name, $value)
    {
        if (!is_string($name) || empty($name)) {
            throw new \InvalidArgumentException("header name must be non-empty string");
        }

        if (is_string($value)) {
            $value = [$value];
        } elseif (empty($value) || !is_array($value)) {
            throw new \InvalidArgumentException("header value must be non-empty strings");
        }

        $value = array_values($this->trimHeaderValues($value));
        foreach ($value as $v) {
            if (!is_string($v)) {
                throw new \InvalidArgumentException("header name must be non-empty string");
            }
        }

        $nameNormalize = strtolower($name);

        $msg = clone $this;

        if (isset($msg->headerNames[$nameNormalize])) {
            $name = $msg->headerNames[$nameNormalize];
            $msg->headers[$name] = array_merge($msg->headers[$name] , $value);
        } else {
            $msg->headerNames[$nameNormalize] = $name;
            $msg->headers[$name] = $value;
        }

        return $msg;
    }

    /**
     * Return an instance without the specified header.
     *
     * Header resolution MUST be done without case-sensitivity.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that removes
     * the named header.
     *
     * @param string $name Case-insensitive header field name to remove.
     * @return static
     */
    public function withoutHeader($name)
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }

        $msg = clone $this;
        $name = $this->headerNames[strtolower($name)];
        unset($msg->headerNames[$name], $msg->headers[$name]);
        return $msg;
    }

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface Returns the body as a stream.
     */
    public function getBody()
    {
        if (is_null($this->stream)) {
            $this->stream = stream_for();
        }
        return $this->stream;
    }

    /**
     * Return an instance with the specified message body.
     *
     * The body MUST be a StreamInterface object.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * new body stream.
     *
     * @param StreamInterface $body Body.
     * @return static
     * @throws \InvalidArgumentException When the body is not valid.
     */
    public function withBody(StreamInterface $body)
    {
        if ($body === $this->stream) {
            return $this;
        }

        $msg = clone $this;
        $msg->stream = $body;
        return $msg;
    }
}
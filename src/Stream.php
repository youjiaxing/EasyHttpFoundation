<?php
namespace EasyHttpFoundation;


use Psr\Http\Message\StreamInterface;

class Stream implements StreamInterface
{
    /* @var int|null */
    private $size;

    /* @var */
    private $customMetadata;

    /* @var resource */
    private $stream;

    /* @var bool */
    private $seekable;

    /* @var string */
    private $uri;

    /* @var bool */
    private $readable;

    /* @var bool */
    private $writable;


    /**
     * This constructor accepts an associative array of options.
     *
     * - size: (int) If a read stream would otherwise have an indeterminate
     *   size, but the size is known due to foreknowledge, then you can
     *   provide that size, in bytes.
     * - metadata: (array) Any additional metadata to return when the metadata
     *   of the stream is accessed.
     *
     * @param resource $stream  Stream resource to wrap.
     * @param array    $options Associative array of options.
     *
     * @throws \InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, array $option = [])
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException("Stream must be a resource");
        }

        if (isset($option['size'])) {
            $this->size = $option['size'];
        }

        $this->customMetadata = isset($option['metadata']) ? $option['metadata'] : [];

        $this->stream = $stream;

        $meta = stream_get_meta_data($this->stream);
        $this->seekable = $meta['seekable'];
        $this->readable = $this->isModeReadable($meta['mode']);
        $this->writable = $this->isModeWritable($meta['mode']);
        $this->uri = $this->getMetadata('uri');
    }

    private function isModeReadable($mode)
    {
        foreach (['r', '+'] as $m) {
            if (strpos($mode, $m) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isModeWritable($mode)
    {
        foreach (['w', '+', 'c', 'x', 'a'] as $m) {
            if (strpos($mode, $m) !== false) {
                return true;
            }
        }

        return false;
    }

    function __destruct()
    {
        $this->close();
    }


    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
    public function __toString()
    {
        try {
            $this->seek(0);
            return (string) stream_get_contents($this->stream);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        if (!isset($this->stream)) {
            return;
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->detach();
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        if (!isset($this->stream)) {
            return null;
        }

        $result = $this->stream;
        unset($this->stream);
        $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;
        return $result;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        if (!is_null($this->size)) {
            return $this->size;
        }

        if (!isset($this->stream)) {
            return null;
        }

        // 在往流中写入数据后, 流的大小将被改变, 因此需要重新获取
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }
        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell()
    {
        $this->validateStream();

        $result = ftell($this->stream);
        if ($result === false) {
            throw new \RuntimeException("Unable to determine stream position");
        }

        return $result;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        $this->validateStream();

        return feof($this->stream);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * Seek to a position in the stream.
     *
     * @link http://www.php.net/manual/en/function.fseek.php
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *     based on the seek offset. Valid values are identical to the built-in
     *     PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *     offset bytes SEEK_CUR: Set position to current location plus offset
     *     SEEK_END: Set position to end-of-stream plus offset.
     * @throws \RuntimeException on failure.
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        $this->validateStream();

        if (!$this->seekable) {
            throw new \RuntimeException("Stream is not seekable");
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException("Unable to seek to stream position {$offset} with whence {$whence}");
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @link http://www.php.net/manual/en/function.fseek.php
     * @throws \RuntimeException on failure.
     */
    public function rewind()
    {
        $this->validateStream();

        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     * @return int Returns the number of bytes written to the stream.
     * @throws \RuntimeException on failure.
     */
    public function write($string)
    {
        $this->validateStream();

        if (!$this->writable) {
            throw new \RuntimeException("Cannot write to a non-writable stream");
        }

        // 在写入任何数据后, 我们没办法获取整个流的大小
        $this->size = null;
        $result = fwrite($this->stream, $string);
        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    public function validateStream()
    {
        if (!isset($this->stream) || !is_resource($this->stream)) {
            throw new \RuntimeException("Stream is detached");
        }
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *     them. Fewer than $length bytes may be returned if underlying stream
     *     call returns fewer bytes.
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read($length)
    {
        $this->validateStream();

        if (!$this->readable) {
            throw new \RuntimeException("Cannot read to a non-readable stream");
        }

        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $string = fread($this->stream, $length);
        if ($string === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $string;
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws \RuntimeException if unable to read or an error occurs while
     *     reading.
     */
    public function getContents()
    {
        $this->validateStream();

        $result = stream_get_contents($this->stream);
        if ($result === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string $key Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        if (!isset($this->stream)) {
            return $key ? null : [];
        } elseif (!$key) {
            return $this->customMetadata + stream_get_meta_data($this->stream);
        } elseif (isset($this->customMetadata[$key])) {
            return $this->customMetadata[$key];
        }

        $meta = stream_get_meta_data($this->stream);
        return isset($meta[$key]) ? $meta[$key] : null;
    }
}
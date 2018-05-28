<?php


namespace EasyHttpFoundation;


use Psr\Http\Message\StreamInterface;

/**
 * Provides a read only stream that pumps data from a PHP callable.
 *
 * When invoking the provided callable, the PumpStream will pass the amount of
 * data requested to read to the callable. The callable can choose to ignore
 * this value and return fewer or more bytes than requested. Any extra data
 * returned by the provided callable is buffered internally until drained using
 * the read() function of the PumpStream. The provided callable MUST return
 * false when there is no more data to read.
 */
class PumpStream implements StreamInterface
{
    /* @var callable */
    private $source;

    /* @var int|null */
    private $size;

    /* @var array */
    private $metadata;

    /* @var int */
    private $tellPos;

    /* @var BufferStream */
    private $buffer;

    /* @var BufferStream */

    /**
     * @param callable $source Source of the stream data. The callable MAY
     *                         accept an integer argument used to control the
     *                         amount of data to return. The callable MUST
     *                         return a string when called, or false on error
     *                         or EOF.
     * @param array $options   Stream options:
     *                         - metadata: Hash of metadata to use with stream.
     *                         - size: Size of the stream, if known.
     */
    public function __construct(callable $source, array $options = [])
    {
        $this->source = $source;
        $this->size = isset($options['size']) ? $options['size'] : null;
        $this->metadata = isset($options['metadata']) ? $options['metadata'] : [];
        $this->buffer = new BufferStream();
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
            return copy_to_string($this);
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
        $this->source = null;
        $this->tellPos = 0;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell()
    {
        return $this->tellPos;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return !$this->source && $this->buffer->eof();
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return false;
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
        throw new \RuntimeException('Cannot seek a PumpStream');
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
        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return false;
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
        throw new \RuntimeException('Cannot write to a PumpStream');
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return true;
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
        $data = $this->buffer->read($length);
        $readLen = strlen($data);

        $remain = $length - strlen($data);
        if ($remain > 0) {
            $this->pump($remain);
            $data .= $this->buffer->read($remain);
            $readLen = strlen($data);
        }

        $this->tellPos += strlen($readLen);
        return $data;
    }

    private function pump($length)
    {
        if ($this->source) {
            do {
                $data = call_user_func_array($this->source, [$length]);
                if ($data === false || $data === null) {
                    $this->source = null;
                    return;
                }
                $this->buffer->write($data);
                $length -= strlen($data);
            } while ($length > 0);
        }
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
        $result = '';
        while (!$this->eof()) {
            $result .= $this->read(1000000);
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
        if (!$key) {
            return $this->metadata;
        }

        return isset($this->metadata[$key]) ? $this->metadata[$key] : null;
    }
}
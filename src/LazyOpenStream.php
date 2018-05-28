<?php


namespace EasyHttpFoundation;


use Psr\Http\Message\StreamInterface;

class LazyOpenStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * @param string $filename File to lazily open
     * @param string $mode     fopen mode to use when opening the stream
     */
    public function __construct($filename, $mode)
    {
        $this->filename = $filename;
        $this->mode = $mode;
    }

    protected function createStream()
    {
        return new Stream(try_fopen($this->filename, $this->mode));
    }
}
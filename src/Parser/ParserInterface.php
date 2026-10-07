<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs\Parser;

use BohnMedia\MediaCodecs\MediaType;

interface ParserInterface
{
    /**
     * Whether the parser understands the container, judged by the first bytes of the file.
     */
    public function supports(string $head): bool;

    /**
     * @param resource $stream A seekable, readable stream positioned anywhere
     */
    public function parse($stream): MediaType|null;
}

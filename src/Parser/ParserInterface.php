<?php

declare(strict_types=1);

namespace BohnMedia\MimeTypeCodecs\Parser;

use BohnMedia\MimeTypeCodecs\MediaType;

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

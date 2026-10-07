<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs;

use BohnMedia\MediaCodecs\Parser\IsoBmffParser;
use BohnMedia\MediaCodecs\Parser\MatroskaParser;
use BohnMedia\MediaCodecs\Parser\ParserInterface;

/**
 * Detects the complete media type (essence and codecs) of audio and video files by reading the container header.
 */
final class MediaTypeDetector
{
    /** @var list<ParserInterface> */
    private readonly array $parsers;

    public function __construct(ParserInterface ...$parsers)
    {
        $this->parsers = array_values($parsers) ?: [new IsoBmffParser(), new MatroskaParser()];
    }

    public function detectFromFile(string $path): MediaType|null
    {
        $stream = @fopen($path, 'r');

        if (false === $stream) {
            return null;
        }

        try {
            return $this->detectFromStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream A seekable, readable stream; unsupported or unreadable containers yield null
     */
    public function detectFromStream($stream): MediaType|null
    {
        if (!\is_resource($stream) || !stream_get_meta_data($stream)['seekable']) {
            return null;
        }

        rewind($stream);
        $head = (string) fread($stream, 12);

        foreach ($this->parsers as $parser) {
            if ($parser->supports($head)) {
                try {
                    return $parser->parse($stream);
                } catch (\Throwable) {
                    return null; // a damaged header must never break the caller
                }
            }
        }

        return null;
    }
}

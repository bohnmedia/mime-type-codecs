<?php

declare(strict_types=1);

namespace BohnMedia\MimeTypeCodecs;

/**
 * A media type with its codecs parameter, e.g. video/mp4; codecs="av01.0.08M.08, mp4a.40.2" (RFC 6381).
 */
final class MediaType implements \Stringable
{
    /**
     * @param list<string> $codecs
     */
    public function __construct(
        private readonly string $essence,
        private readonly array $codecs = [],
    ) {
    }

    /**
     * The media type without parameters, e.g. "video/mp4".
     */
    public function getEssence(): string
    {
        return $this->essence;
    }

    /**
     * The codecs in track order, video first, e.g. ["av01.0.08M.08", "mp4a.40.2"].
     *
     * @return list<string>
     */
    public function getCodecs(): array
    {
        return $this->codecs;
    }

    public function hasCodecs(): bool
    {
        return [] !== $this->codecs;
    }

    /**
     * The complete media type as used in the type attribute of a <source> element.
     */
    public function __toString(): string
    {
        return $this->codecs ? sprintf('%s; codecs="%s"', $this->essence, implode(', ', $this->codecs)) : $this->essence;
    }
}

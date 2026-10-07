<?php

declare(strict_types=1);

namespace BohnMedia\MimeTypeCodecs\Tests;

use BohnMedia\MimeTypeCodecs\MediaType;
use BohnMedia\MimeTypeCodecs\MediaTypeDetector;
use PHPUnit\Framework\TestCase;

/**
 * Matroska allows the Tracks behind the Clusters if a SeekHead before the first Cluster points to them (RFC 9559).
 * ffmpeg and mkvmerge do not write such files, so the tests build them in memory.
 */
class MatroskaSeekHeadTest extends TestCase
{
    private const TRACKS = "\x16\x54\xAE\x6B";
    private const SEEK_HEAD = "\x11\x4D\x9B\x74";

    public function testFollowsTheSeekHeadToTracksAfterTheClusters(): void
    {
        $seekHead = $this->seekHead(self::TRACKS, 0); // the position does not change the length of the SeekHead
        $cluster = $this->cluster();
        $segment = $this->seekHead(self::TRACKS, \strlen($seekHead) + \strlen($cluster)).$cluster.$this->tracks();

        $this->assertSame('video/webm; codecs="vp8, opus"', (string) $this->detect($segment));
    }

    public function testFollowsASecondSeekHeadAtTheEnd(): void
    {
        $seekHead = $this->seekHead(self::SEEK_HEAD, 0);
        $cluster = $this->cluster();
        $tracks = $this->tracks();
        $tracksPosition = \strlen($seekHead) + \strlen($cluster);
        $segment = $this->seekHead(self::SEEK_HEAD, $tracksPosition + \strlen($tracks)).$cluster.$tracks.$this->seekHead(self::TRACKS, $tracksPosition);

        $this->assertSame('video/webm; codecs="vp8, opus"', (string) $this->detect($segment));
    }

    public function testIgnoresAnOutdatedSeekHead(): void
    {
        $seekHead = $this->seekHead(self::TRACKS, 0);
        $segment = $this->seekHead(self::TRACKS, \strlen($seekHead)).$this->cluster().$this->tracks(); // points to the Cluster

        $this->assertNull($this->detect($segment));
        $this->assertNull($this->detect($this->seekHead(self::TRACKS, PHP_INT_MAX).$this->cluster()));
    }

    private function detect(string $segment): MediaType|null
    {
        $ebml = $this->element("\x1A\x45\xDF\xA3", $this->element("\x42\x82", 'webm'));
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $ebml.$this->element("\x18\x53\x80\x67", $segment));

        return (new MediaTypeDetector())->detectFromStream($stream);
    }

    private function seekHead(string $id, int $position): string
    {
        return $this->element(self::SEEK_HEAD, $this->element("\x4D\xBB", $this->element("\x53\xAB", $id).$this->element("\x53\xAC", pack('J', $position))));
    }

    private function cluster(): string
    {
        return $this->element("\x1F\x43\xB6\x75", $this->element("\xE7", "\x00").$this->element("\xA3", str_repeat("\x00", 64)));
    }

    private function tracks(): string
    {
        return $this->element(self::TRACKS,
            $this->element("\xAE", $this->element("\x83", "\x01").$this->element("\x86", 'V_VP8'))
            .$this->element("\xAE", $this->element("\x83", "\x02").$this->element("\x86", 'A_OPUS')),
        );
    }

    /**
     * Element with an eight-byte size, so the length of the data never changes the length of the header.
     */
    private function element(string $id, string $data): string
    {
        return $id."\x01".substr(pack('J', \strlen($data)), 1).$data;
    }
}

<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs\Parser;

use BohnMedia\MediaCodecs\MediaType;

/**
 * WebM and Matroska (EBML). Reads the DocType and the Tracks element: TrackType, CodecID and CodecPrivate of every track.
 * Tracks are part of the header and usually follow SeekHead and Info; Clusters are skipped by seeking.
 */
final class MatroskaParser implements ParserInterface
{
    private const EBML = 0x1A45DFA3;
    private const SEGMENT = 0x18538067;
    private const TRACKS = 0x1654AE6B;
    private const TRACK_ENTRY = 0xAE;
    private const TRACK_TYPE = 0x83;
    private const CODEC_ID = 0x86;
    private const CODEC_PRIVATE = 0x63A2;
    private const DOC_TYPE = 0x4282;

    /** @var resource */
    private $stream;

    public function supports(string $head): bool
    {
        return str_starts_with($head, "\x1A\x45\xDF\xA3");
    }

    public function parse($stream): MediaType|null
    {
        $this->stream = $stream;
        rewind($stream);

        $docType = 'matroska';
        $tracks = [];

        foreach ($this->children(0, fstat($stream)['size'] ?? 0) as [$id, $start, $end]) {
            if (self::EBML === $id) {
                foreach ($this->children($start, $end) as [$childId, $childStart, $childEnd]) {
                    if (self::DOC_TYPE === $childId) {
                        $docType = $this->read($childStart, $childEnd - $childStart);
                    }
                }
            } elseif (self::SEGMENT === $id) {
                foreach ($this->children($start, $end) as [$childId, $childStart, $childEnd]) {
                    if (self::TRACKS === $childId) {
                        $tracks = $this->readTracks($childStart, $childEnd);
                        break 2;
                    }

                    if (0x1F43B675 === $childId) { // first Cluster: the header is over
                        break 2;
                    }
                }
            }
        }

        if (!$tracks) {
            return null;
        }

        $video = array_filter($tracks, static fn (array $t): bool => 1 === $t['type']);
        $audio = array_filter($tracks, static fn (array $t): bool => 2 === $t['type']);
        $codecs = array_values(array_unique(array_filter(array_map(static fn (array $t): string|null => $t['codec'], [...$video, ...$audio]))));
        $essence = ('webm' === $docType ? ($video ? 'video/webm' : 'audio/webm') : ($video ? 'video/x-matroska' : 'audio/x-matroska'));

        return new MediaType($essence, $codecs);
    }

    /**
     * @return list<array{type: int, codec: string|null}>
     */
    private function readTracks(int $start, int $end): array
    {
        $tracks = [];

        foreach ($this->children($start, $end) as [$id, $entryStart, $entryEnd]) {
            if (self::TRACK_ENTRY !== $id) {
                continue;
            }

            $type = 0;
            $codecId = '';
            $private = '';

            foreach ($this->children($entryStart, $entryEnd) as [$childId, $childStart, $childEnd]) {
                $value = $this->read($childStart, $childEnd - $childStart);

                match ($childId) {
                    self::TRACK_TYPE => $type = \ord($value[0] ?? "\0"),
                    self::CODEC_ID => $codecId = $value,
                    self::CODEC_PRIVATE => $private = $value,
                    default => null,
                };
            }

            $tracks[] = ['type' => $type, 'codec' => $this->codec($codecId, $private)];
        }

        return $tracks;
    }

    private function codec(string $codecId, string $private): string|null
    {
        return match (true) {
            'V_AV1' === $codecId => Codecs::av1($private),
            'V_VP9' === $codecId => \strlen($private) >= 6 && 1 === \ord($private[0]) ? sprintf('vp09.%02d.%02d.%02d', \ord($private[2]), \ord($private[5] ?? "\0") ?: 10, \ord($private[8] ?? "\x08")) : 'vp9',
            'V_VP8' === $codecId => 'vp8',
            'V_MPEG4/ISO/AVC' === $codecId => Codecs::avc($private),
            'V_MPEGH/ISO/HEVC' === $codecId => Codecs::hevc($private),
            'A_OPUS' === $codecId => 'opus',
            'A_VORBIS' === $codecId => 'vorbis',
            'A_FLAC' === $codecId => 'flac',
            str_starts_with($codecId, 'A_AAC') => 'mp4a.40.2',
            'A_MPEG/L3' === $codecId => 'mp3',
            default => null,
        };
    }

    /**
     * Iterates the elements between $start and $end: [id, data start, data end].
     *
     * @return \Generator<array{0: int, 1: int, 2: int}>
     */
    private function children(int $start, int $end): \Generator
    {
        $offset = $start;

        while ($offset + 2 <= $end) {
            fseek($this->stream, $offset);
            $buffer = (string) fread($this->stream, 12);

            if (\strlen($buffer) < 2) {
                return;
            }

            [$id, $idLength] = $this->vint($buffer, 0, false);
            [$size, $sizeLength] = $this->vint($buffer, $idLength, true);

            if (null === $id || null === $size) {
                return;
            }

            $dataStart = $offset + $idLength + $sizeLength;
            $dataEnd = PHP_INT_MAX === $size ? $end : min($dataStart + $size, $end); // unknown size: extends to the parent

            yield [$id, $dataStart, $dataEnd];

            $offset = $dataEnd;
        }
    }

    /**
     * Reads an EBML variable-length integer. Element IDs keep their length marker, sizes have it stripped.
     *
     * @return array{0: int|null, 1: int}
     */
    private function vint(string $buffer, int $offset, bool $stripMarker): array
    {
        $first = \ord($buffer[$offset] ?? "\0");

        if (0 === $first) {
            return [null, 1];
        }

        $length = 1;

        while (!($first & (0x80 >> ($length - 1)))) {
            ++$length;
        }

        if ($offset + $length > \strlen($buffer)) {
            return [null, $length];
        }

        $value = $stripMarker ? $first & (0xFF >> $length) : $first;
        $allOnes = $stripMarker && $value === (0xFF >> $length);

        for ($i = 1; $i < $length; ++$i) {
            $byte = \ord($buffer[$offset + $i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && 0xFF === $byte;
        }

        return [$allOnes ? PHP_INT_MAX : $value, $length]; // all ones = unknown size (live streams)
    }

    private function read(int $start, int $length): string
    {
        fseek($this->stream, $start);

        return $length > 0 ? (string) fread($this->stream, $length) : '';
    }
}

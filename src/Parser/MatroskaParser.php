<?php

declare(strict_types=1);

namespace BohnMedia\MimeTypeCodecs\Parser;

use BohnMedia\MimeTypeCodecs\MediaType;

/**
 * WebM and Matroska (EBML). Reads the DocType and the Tracks element: TrackType, CodecID and CodecPrivate of every track.
 * Tracks usually precede the first Cluster. If not, a SeekHead before the first Cluster must point to them (RFC 9559),
 * so the Clusters themselves are never read.
 */
final class MatroskaParser implements ParserInterface
{
    private const EBML = 0x1A45DFA3;
    private const SEGMENT = 0x18538067;
    private const SEEK_HEAD = 0x114D9B74;
    private const SEEK = 0x4DBB;
    private const SEEK_ID = 0x53AB;
    private const SEEK_POSITION = 0x53AC;
    private const CLUSTER = 0x1F43B675;
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

        foreach ($this->children(0, (int) ((fstat($stream) ?: [])['size'] ?? 0)) as [$id, $start, $end]) {
            if (self::EBML === $id) {
                foreach ($this->children($start, $end) as [$childId, $childStart, $childEnd]) {
                    if (self::DOC_TYPE === $childId) {
                        $docType = $this->read($childStart, $childEnd - $childStart);
                    }
                }
            } elseif (self::SEGMENT === $id) {
                $tracks = $this->readSegment($start, $end);
                break;
            }
        }

        if (!$tracks) {
            return null;
        }

        $video = array_filter($tracks, static fn (array $t): bool => 1 === $t['type']);
        $audio = array_filter($tracks, static fn (array $t): bool => 2 === $t['type']);
        $essence = ('webm' === $docType ? ($video ? 'video/webm' : 'audio/webm') : ($video ? 'video/x-matroska' : 'audio/x-matroska'));

        return new MediaType($essence, Codecs::collect([...$video, ...$audio]));
    }

    /**
     * Reads the Tracks before the first Cluster or, if they are stored at the end, follows the SeekHead to them.
     *
     * @return list<array{type: int, codec: string|null}>
     */
    private function readSegment(int $start, int $end): array
    {
        $positions = [];

        foreach ($this->children($start, $end) as [$id, $childStart, $childEnd]) {
            if (self::TRACKS === $id) {
                return $this->readTracks($childStart, $childEnd);
            }

            if (self::SEEK_HEAD === $id) {
                $positions = [...$positions, ...$this->readSeekHead($childStart, $childEnd)];
            }

            if (self::CLUSTER === $id) { // the header is over
                break;
            }
        }

        // A SeekHead may point to a second SeekHead at the end of the file, which then lists the Tracks
        foreach ($positions as [$id, $position]) {
            if (self::SEEK_HEAD === $id && null !== ($element = $this->elementAt($start + $position, $end, self::SEEK_HEAD))) {
                $positions = [...$positions, ...$this->readSeekHead(...$element)];
            }
        }

        foreach ($positions as [$id, $position]) {
            if (self::TRACKS === $id && null !== ($element = $this->elementAt($start + $position, $end, self::TRACKS))) {
                return $this->readTracks(...$element);
            }
        }

        return [];
    }

    /**
     * Returns the referenced element IDs with their positions relative to the start of the Segment data.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function readSeekHead(int $start, int $end): array
    {
        $positions = [];

        foreach ($this->children($start, $end) as [$id, $seekStart, $seekEnd]) {
            if (self::SEEK !== $id) {
                continue;
            }

            $seekId = $position = null;

            foreach ($this->children($seekStart, $seekEnd) as [$childId, $childStart, $childEnd]) {
                match ($childId) {
                    self::SEEK_ID => $seekId = $this->uint($this->read($childStart, $childEnd - $childStart)), // the ID including its length marker
                    self::SEEK_POSITION => $position = $this->uint($this->read($childStart, $childEnd - $childStart)),
                    default => null,
                };
            }

            if (null !== $seekId && null !== $position) {
                $positions[] = [$seekId, $position];
            }
        }

        return $positions;
    }

    /**
     * Returns data start and end of the element at $offset if it has the expected ID; a SeekHead may be outdated.
     *
     * @return array{0: int, 1: int}|null
     */
    private function elementAt(int $offset, int $end, int $expectedId): array|null
    {
        if ($offset < 0 || $offset >= $end) {
            return null;
        }

        foreach ($this->children($offset, $end) as [$id, $start, $elementEnd]) {
            return $expectedId === $id ? [$start, $elementEnd] : null;
        }

        return null;
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
                    self::TRACK_TYPE => $type = $this->uint($value),
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
            'V_VP9' === $codecId => Codecs::vp9FromMatroska($private) ?? 'vp9', // the CodecPrivate is optional, browsers accept the short form
            'V_VP8' === $codecId => 'vp8',
            'V_MPEG4/ISO/AVC' === $codecId => Codecs::avc($private),
            'V_MPEGH/ISO/HEVC' === $codecId => Codecs::hevc($private),
            'A_OPUS' === $codecId => 'opus',
            'A_VORBIS' === $codecId => 'vorbis',
            'A_FLAC' === $codecId => 'flac',
            str_starts_with($codecId, 'A_AAC') => Codecs::aac($private) ?? match ($codecId) { // old IDs without CodecPrivate
                'A_AAC/MPEG4/LC', 'A_AAC/MPEG2/LC' => 'mp4a.40.2',
                'A_AAC/MPEG4/LC/SBR', 'A_AAC/MPEG2/LC/SBR' => 'mp4a.40.5',
                default => null,
            },
            'A_AC3' === $codecId => 'ac-3',
            'A_EAC3' === $codecId => 'ec-3',
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

    /**
     * EBML unsigned integers have 0 to 8 bytes, big endian.
     */
    private function uint(string $bytes): int
    {
        $value = 0;

        for ($i = 0, $n = \strlen($bytes); $i < $n; ++$i) {
            $value = ($value << 8) | \ord($bytes[$i]);
        }

        return $value;
    }

    private function read(int $start, int $length): string
    {
        fseek($this->stream, $start);

        return $length > 0 ? (string) fread($this->stream, $length) : '';
    }
}

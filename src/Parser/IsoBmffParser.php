<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs\Parser;

use BohnMedia\MediaCodecs\MediaType;

/**
 * MP4, M4A, M4V and QuickTime (ISO/IEC 14496-12). Walks moov → trak → mdia → minf → stbl → stsd and reads the codec
 * configuration of every sample entry. Only box headers are read; everything else is skipped by seeking.
 */
final class IsoBmffParser implements ParserInterface
{
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** @var resource */
    private $stream;

    private string|null $handler = null;

    /** @var list<array{handler: string|null, codec: string|null}> */
    private array $tracks = [];

    public function supports(string $head): bool
    {
        return 'ftyp' === substr($head, 4, 4) || 'moov' === substr($head, 4, 4) || 'wide' === substr($head, 4, 4) || 'mdat' === substr($head, 4, 4);
    }

    public function parse($stream): MediaType|null
    {
        $this->stream = $stream;
        $this->tracks = [];
        $this->handler = null;

        rewind($stream);
        $brand = $this->readBrand();
        $this->walk(0, $this->size(), '');

        if (!$this->tracks) {
            return null;
        }

        $video = array_filter($this->tracks, static fn (array $t): bool => 'vide' === $t['handler']);
        $audio = array_filter($this->tracks, static fn (array $t): bool => 'soun' === $t['handler']);
        $codecs = array_values(array_unique(array_filter(array_map(static fn (array $t): string|null => $t['codec'], [...$video, ...$audio]))));

        if ('qt  ' === $brand) {
            $essence = 'video/quicktime';
        } else {
            $essence = $video ? 'video/mp4' : 'audio/mp4';
        }

        return new MediaType($essence, $codecs);
    }

    private function walk(int $start, int $end, string $parent): void
    {
        for ($offset = $start; $offset + 8 <= $end; $offset += $size) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 16);

            if (\strlen($header) < 8) {
                return;
            }

            $size = $this->uint32($header, 0);
            $type = substr($header, 4, 4);
            $headerSize = 8;

            if (1 === $size && \strlen($header) >= 16) { // 64 bit size
                $size = ($this->uint32($header, 8) << 32) | $this->uint32($header, 12);
                $headerSize = 16;
            } elseif (0 === $size) { // box extends to the end of the file
                $size = $end - $offset;
            }

            if ($size < $headerSize) {
                return;
            }

            if ('trak' === $type) {
                $this->handler = null; // each track declares its own handler
            }

            if ('hdlr' === $type && 'mdia' === $parent) { // QuickTime files have a second (data) handler in minf
                fseek($this->stream, $offset + $headerSize + 8);
                $this->handler = (string) fread($this->stream, 4);
            } elseif ('stsd' === $type) {
                $this->readSampleDescriptions($offset + $headerSize, $offset + $size);
            } elseif (\in_array($type, self::CONTAINERS, true)) {
                $this->walk($offset + $headerSize, $offset + $size, $type);
            }
        }
    }

    private function readSampleDescriptions(int $start, int $end): void
    {
        fseek($this->stream, $start);
        $count = $this->uint32((string) fread($this->stream, 8)."\0\0\0\0\0\0\0\0", 4); // version/flags, entry count
        $offset = $start + 8;

        for ($i = 0; $i < $count && $offset + 16 <= $end; ++$i) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 8);

            if (\strlen($header) < 8) {
                break;
            }

            $size = $this->uint32($header, 0);
            $fourcc = substr($header, 4, 4);

            if ($size < 8) {
                break;
            }

            // Sample entry: 6 reserved bytes + data reference index, then 70 bytes (video) or 20/36 bytes (audio) of fields
            $this->tracks[] = ['handler' => $this->handler, 'codec' => $this->readCodec($fourcc, $offset + 8, $offset + $size)];
            $offset += $size;
        }
    }

    private function readCodec(string $fourcc, int $start, int $end): string|null
    {
        return match ($fourcc) {
            'av01' => Codecs::av1($this->findBox($start + 78, $end, 'av1C') ?? ''),
            'avc1', 'avc3' => Codecs::avc($this->findBox($start + 78, $end, 'avcC') ?? '', $fourcc),
            'hvc1', 'hev1' => Codecs::hevc($this->findBox($start + 78, $end, 'hvcC') ?? '', $fourcc),
            'vp09' => Codecs::vp9(substr($this->findBox($start + 78, $end, 'vpcC') ?? '', 4)),
            'mp4a' => Codecs::mp4a($this->findBoxInAudioEntry($start, $end, 'esds') ?? ''),
            'Opus' => 'opus',
            'fLaC' => 'flac',
            'ac-3' => 'ac-3',
            'ec-3' => 'ec-3',
            'mp3 ', '.mp3' => 'mp3',
            default => null,
        };
    }

    /**
     * Audio sample entries have a version-dependent length (QuickTime v1/v2 add fields), so the esds box is searched after
     * the common 28 bytes.
     */
    private function findBoxInAudioEntry(int $start, int $end, string $type): string|null
    {
        foreach ([28, 44, 64] as $fieldsLength) {
            if (null !== ($payload = $this->findBox($start + $fieldsLength, $end, $type))) {
                return substr($payload, 4); // skip version/flags of the full box
            }
        }

        return null;
    }

    /**
     * Returns the payload of the first child box of the given type between $start and $end.
     */
    private function findBox(int $start, int $end, string $type): string|null
    {
        for ($offset = $start; $offset + 8 <= $end; $offset += $size) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 8);

            if (\strlen($header) < 8) {
                return null;
            }

            $size = $this->uint32($header, 0);

            if ($size < 8 || $offset + $size > $end) {
                return null;
            }

            if (substr($header, 4, 4) === $type) {
                return 8 === $size ? '' : (string) fread($this->stream, $size - 8);
            }
        }

        return null;
    }

    private function readBrand(): string|null
    {
        $header = (string) fread($this->stream, 12);

        return 'ftyp' === substr($header, 4, 4) ? substr($header, 8, 4) : null;
    }

    private function size(): int
    {
        return (int) (fstat($this->stream)['size'] ?? 0);
    }

    private function uint32(string $bytes, int $offset): int
    {
        return (\ord($bytes[$offset]) << 24) | (\ord($bytes[$offset + 1]) << 16) | (\ord($bytes[$offset + 2]) << 8) | \ord($bytes[$offset + 3]);
    }
}

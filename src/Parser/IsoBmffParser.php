<?php

declare(strict_types=1);

namespace BohnMedia\MimeTypeCodecs\Parser;

use BohnMedia\MimeTypeCodecs\MediaType;

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
        return \in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'wide', 'free', 'skip'], true);
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

        // Files without ftyp are old QuickTime movies; an ISO file always starts with ftyp
        if (null === $brand || 'qt  ' === $brand) {
            $essence = 'video/quicktime';
        } else {
            $essence = $video ? 'video/mp4' : 'audio/mp4';
        }

        return new MediaType($essence, Codecs::collect([...$video, ...$audio]));
    }

    private function walk(int $start, int $end, string $parent): void
    {
        for ($offset = $start; $offset + 8 <= $end; $offset += $size) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 16);

            if (\strlen($header) < 8) {
                return;
            }

            $size = Codecs::uint32($header, 0);
            $type = substr($header, 4, 4);
            $headerSize = 8;

            if (1 === $size && \strlen($header) >= 16) { // 64 bit size
                $size = (Codecs::uint32($header, 8) << 32) | Codecs::uint32($header, 12);
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
        $count = Codecs::uint32((string) fread($this->stream, 8)."\0\0\0\0\0\0\0\0", 4); // version/flags, entry count
        $offset = $start + 8;

        for ($i = 0; $i < $count && $offset + 16 <= $end; ++$i) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 8);

            if (\strlen($header) < 8) {
                break;
            }

            $size = Codecs::uint32($header, 0);
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
            'mp4a' => null !== ($esds = $this->findEsds($start, $end)) ? Codecs::mp4a($esds) : null,
            'Opus' => 'opus',
            'fLaC' => 'flac',
            'ac-3' => 'ac-3',
            'ec-3' => 'ec-3',
            'mp3 ', '.mp3' => 'mp3',
            default => null,
        };
    }

    /**
     * Returns the ES descriptors of an mp4a sample entry (without the version/flags of the esds box). The fields of an
     * audio sample entry are 20 bytes long; QuickTime version 1 and 2 add 16 or 36 bytes and wrap the esds box in a
     * wave box.
     */
    private function findEsds(int $start, int $end): string|null
    {
        fseek($this->stream, $start + 8); // reserved (6), data reference index (2)
        $version = unpack('n', (string) fread($this->stream, 2)."\0\0")[1] ?? 0;
        $childrenStart = $start + 8 + ([0 => 20, 1 => 36, 2 => 56][$version] ?? 20);

        if (null !== ($wave = $this->locateBox($childrenStart, $end, 'wave'))) {
            [$childrenStart, $end] = $wave;
        }

        $esds = $this->findBox($childrenStart, $end, 'esds');

        return null !== $esds ? substr($esds, 4) : null;
    }

    /**
     * Returns the payload of the first child box of the given type between $start and $end.
     */
    private function findBox(int $start, int $end, string $type): string|null
    {
        if (null === ($box = $this->locateBox($start, $end, $type))) {
            return null;
        }

        [$payloadStart, $payloadEnd] = $box;
        $length = $payloadEnd - $payloadStart;

        if ($length < 1) {
            return '';
        }

        fseek($this->stream, $payloadStart);

        return (string) fread($this->stream, $length);
    }

    /**
     * Returns start and end of the payload of the first child box of the given type between $start and $end.
     *
     * @return array{0: int, 1: int}|null
     */
    private function locateBox(int $start, int $end, string $type): array|null
    {
        for ($offset = $start; $offset + 8 <= $end; $offset += $size) {
            fseek($this->stream, $offset);
            $header = (string) fread($this->stream, 8);

            if (\strlen($header) < 8) {
                return null;
            }

            $size = Codecs::uint32($header, 0);

            if ($size < 8 || $offset + $size > $end) {
                return null;
            }

            if (substr($header, 4, 4) === $type) {
                return [$offset + 8, $offset + $size];
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
        return (int) ((fstat($this->stream) ?: [])['size'] ?? 0);
    }
}

<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs\Parser;

/**
 * Builds RFC 6381 codec strings from the decoder configuration records of the containers.
 *
 * @internal
 */
final class Codecs
{
    /**
     * AV1CodecConfigurationRecord (av1C): av01.P.LLT.DD, e.g. av01.0.08M.08
     */
    public static function av1(string $record): string|null
    {
        if (\strlen($record) < 4) {
            return null;
        }

        $b1 = \ord($record[1]);
        $b2 = \ord($record[2]);
        $profile = $b1 >> 5;
        $level = $b1 & 0x1F;
        $tier = ($b2 >> 7) & 1 ? 'H' : 'M';
        $highBitDepth = ($b2 >> 6) & 1;
        $twelveBit = ($b2 >> 5) & 1;
        $bitDepth = $twelveBit ? 12 : ($highBitDepth ? 10 : 8);

        return sprintf('av01.%d.%02d%s.%02d', $profile, $level, $tier, $bitDepth);
    }

    /**
     * AVCDecoderConfigurationRecord (avcC): avc1.PPCCLL, e.g. avc1.64001F
     */
    public static function avc(string $record, string $fourcc = 'avc1'): string|null
    {
        if (\strlen($record) < 4) {
            return null;
        }

        return sprintf('%s.%02X%02X%02X', $fourcc, \ord($record[1]), \ord($record[2]), \ord($record[3]));
    }

    /**
     * HEVCDecoderConfigurationRecord (hvcC): hvc1.P.C.LT.CC, e.g. hvc1.1.6.L93.B0 (ISO/IEC 14496-15, Annex E)
     */
    public static function hevc(string $record, string $fourcc = 'hvc1'): string|null
    {
        if (\strlen($record) < 13) {
            return null;
        }

        $b1 = \ord($record[1]);
        $profileSpace = $b1 >> 6;
        $tier = ($b1 >> 5) & 1 ? 'H' : 'L';
        $profileIdc = $b1 & 0x1F;
        $compatibility = self::uint32($record, 2);
        $levelIdc = \ord($record[12]);

        // The 32 compatibility flags are written in reverse bit order as hex without leading zeros
        $reversed = 0;

        for ($i = 0; $i < 32; ++$i) {
            $reversed = ($reversed << 1) | (($compatibility >> $i) & 1);
        }

        // Constraint indicator flags: six bytes, trailing zero bytes omitted
        $constraints = rtrim(substr($record, 6, 6), "\0");
        $parts = [];

        for ($i = 0, $n = \strlen($constraints); $i < $n; ++$i) {
            $parts[] = sprintf('%X', \ord($constraints[$i]));
        }

        return sprintf('%s.%s%d.%X.%s%d%s', $fourcc, ['', 'A', 'B', 'C'][$profileSpace], $profileIdc, $reversed, $tier, $levelIdc, $parts ? '.'.implode('.', $parts) : '');
    }

    private static function uint32(string $bytes, int $offset): int
    {
        return (\ord($bytes[$offset]) << 24) | (\ord($bytes[$offset + 1]) << 16) | (\ord($bytes[$offset + 2]) << 8) | \ord($bytes[$offset + 3]);
    }

    /**
     * VPCodecConfigurationRecord (vpcC payload after version/flags): vp09.PP.LL.DD, e.g. vp09.00.10.08
     */
    public static function vp9(string $record): string|null
    {
        if (\strlen($record) < 3) {
            return null;
        }

        return sprintf('vp09.%02d.%02d.%02d', \ord($record[0]), \ord($record[1]), \ord($record[2]) >> 4);
    }

    /**
     * MPEG-4 audio from the ES descriptor (esds): mp4a.OO.A, e.g. mp4a.40.2 for AAC-LC
     */
    public static function mp4a(string $esds): string|null
    {
        $offset = 0;
        $length = \strlen($esds);
        $objectType = null;

        while ($offset < $length) {
            $tag = \ord($esds[$offset++]);
            $size = 0;

            // Descriptor sizes are stored as up to four 7-bit groups
            for ($i = 0; $i < 4 && $offset < $length; ++$i) {
                $byte = \ord($esds[$offset++]);
                $size = ($size << 7) | ($byte & 0x7F);

                if (!($byte & 0x80)) {
                    break;
                }
            }

            if (0x03 === $tag) { // ES_Descriptor: ES_ID (2), flags (1), then nested descriptors
                $flags = \ord($esds[$offset + 2] ?? "\0");
                $offset += 3 + ($flags & 0x80 ? 2 : 0) + ($flags & 0x40 ? 1 + \ord($esds[$offset + 3] ?? "\0") : 0) + ($flags & 0x20 ? 2 : 0);
                continue;
            }

            if (0x04 === $tag) { // DecoderConfigDescriptor: objectTypeIndication (1), streamType (1), bufferSize (3), bitrates (8)
                $objectType = \ord($esds[$offset] ?? "\0");
                $offset += 13;
                continue;
            }

            if (0x05 === $tag && null !== $objectType) { // DecoderSpecificInfo: first five bits = audioObjectType
                $audioObjectType = \ord($esds[$offset] ?? "\0") >> 3;

                if (31 === $audioObjectType && $offset + 1 < $length) {
                    $audioObjectType = 32 + (((\ord($esds[$offset]) & 0x07) << 3) | (\ord($esds[$offset + 1]) >> 5));
                }

                return sprintf('mp4a.%02X.%d', $objectType, $audioObjectType);
            }

            $offset += $size;
        }

        return null !== $objectType ? sprintf('mp4a.%02X', $objectType) : null;
    }
}

<?php

declare(strict_types=1);

namespace BohnMedia\MediaCodecs\Tests;

use BohnMedia\MediaCodecs\Parser\Codecs;
use PHPUnit\Framework\TestCase;

/**
 * Cases that ffmpeg cannot produce with the available encoders, built from the byte layout of the specifications.
 */
class CodecsTest extends TestCase
{
    public function testReadsTheEsDescriptorWithOptionalFields(): void
    {
        // ES_Descriptor with streamDependenceFlag and URL_Flag set: ES_ID, flags, dependsOn_ES_ID, URL "ab"
        $es = "\x00\x01\xC0\x00\x02\x02ab";
        $decoderConfig = "\x40\x15".str_repeat("\x00", 11);
        $esds = "\x03".\chr(\strlen($es) + 2 + \strlen($decoderConfig) + 4).$es
            ."\x04".\chr(\strlen($decoderConfig) + 4).$decoderConfig
            ."\x05\x02\x13\x90"; // AudioSpecificConfig: audioObjectType 2

        $this->assertSame('mp4a.40.2', Codecs::mp4a($esds));
    }

    public function testReadsTheAudioObjectTypeOfAacInMatroska(): void
    {
        $this->assertSame('mp4a.40.2', Codecs::aac("\x12\x10"));
        $this->assertSame('mp4a.40.5', Codecs::aac("\x2B\x92\x08\x00")); // HE-AAC (SBR)
        $this->assertSame('mp4a.40.42', Codecs::aac("\xF9\x40")); // escape value 31 → 32 + 10
        $this->assertNull(Codecs::aac(''));
    }

    public function testReadsTheVp9CodecPrivateOfMatroska(): void
    {
        $this->assertSame('vp09.00.31.08', Codecs::vp9FromMatroska("\x01\x01\x00\x02\x01\x1F\x03\x01\x08\x04\x01\x01"));
        $this->assertSame('vp09.02.40.10', Codecs::vp9FromMatroska("\x03\x01\x0A\x01\x01\x02\x02\x01\x28")); // any order
        $this->assertNull(Codecs::vp9FromMatroska("\x01\x01\x00\x02\x01\x1F")); // bit depth missing
    }

    public function testOmitsAllCodecsIfOneIsUnknown(): void
    {
        $this->assertSame(['avc1.64001F', 'mp4a.40.2'], Codecs::collect([['codec' => 'avc1.64001F'], ['codec' => 'mp4a.40.2'], ['codec' => 'mp4a.40.2']]));
        $this->assertSame([], Codecs::collect([['codec' => null], ['codec' => 'mp4a.40.2']]));
    }
}

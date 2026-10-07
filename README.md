# bohnmedia/media-codecs

Reads the complete media type of audio and video files from the container header, including the
[RFC 6381 `codecs` parameter](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Formats/codecs_parameter):

```
video/mp4; codecs="av01.0.08M.10, mp4a.40.2"
```

No external tools, no dependencies. Only box and element headers are read; large files are skipped by seeking,
so detection takes well under a millisecond regardless of file size.

## Why

A MIME type guessed from the file extension or with `finfo` only tells the container. AV1, H.265 and H.264 all live
in `.mp4` and all end up as `video/mp4`. Browsers choose the first `<source>` they can play, and without the
`codecs` parameter they have to download a source before they can reject it. With it, they skip unsupported sources
without a request:

```html
<video>
  <source src="video.av1.mp4"  type='video/mp4; codecs="av01.0.08M.10, mp4a.40.2"'>
  <source src="video.hevc.mp4" type='video/mp4; codecs="hvc1.1.6.L120.90, mp4a.40.2"'>
  <source src="video.h264.mp4" type='video/mp4; codecs="avc1.4D0034, mp4a.40.2"'>
</video>
```

## Usage

```php
use BohnMedia\MediaCodecs\MediaTypeDetector;

$mediaType = (new MediaTypeDetector())->detectFromFile('video.mp4');
// or: ->detectFromStream($seekableStream)

(string) $mediaType;          // 'video/mp4; codecs="av01.0.08M.10, mp4a.40.2"'
$mediaType->getEssence();     // 'video/mp4'
$mediaType->getCodecs();      // ['av01.0.08M.10', 'mp4a.40.2']
```

`null` is returned for files that are not a supported container, cannot be read or have no recognizable tracks.
A damaged header never throws.

## Supported

| Container | Essence | Video codecs | Audio codecs |
|---|---|---|---|
| MP4, M4A, M4V (ISO BMFF) | `video/mp4`, `audio/mp4` | AV1 (`av01`), HEVC (`hvc1`, `hev1`), H.264 (`avc1`, `avc3`), VP9 (`vp09`) | AAC and other MPEG-4 audio (`mp4a`), Opus, FLAC, AC-3, E-AC-3, MP3 |
| QuickTime | `video/quicktime` | as above | as above |
| WebM | `video/webm`, `audio/webm` | AV1, VP9, VP8 | Opus, Vorbis |
| Matroska | `video/x-matroska`, `audio/x-matroska` | AV1, VP9, VP8, HEVC, H.264 | Opus, Vorbis, FLAC, AAC, MP3 |

Profile, level and bit depth are read from the decoder configuration records (`av1C`, `avcC`, `hvcC`, `vpcC`, `esds`),
so the strings are exact for the file at hand. For VP9 in WebM without `CodecPrivate` the short form `vp9` is used,
which browsers accept.

## Development

```bash
composer install
composer test
composer phpstan
tests/Fixtures/generate.sh   # regenerates the fixtures with ffmpeg
```

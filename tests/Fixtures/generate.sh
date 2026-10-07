#!/usr/bin/env bash
# Generates the test fixtures with ffmpeg: one tiny frame (16×16) and 0.1 s of silence per file.
# Expected codec strings are verified with ffprobe in generate-expectations.php.
set -euo pipefail
cd "$(dirname "$0")"
V="-f lavfi -i color=c=red:s=16x16:d=0.1:r=10"
A="-f lavfi -i sine=frequency=440:duration=0.1"
Q="-hide_banner -loglevel error -y"
ffmpeg $Q $V $A -c:v libaom-av1 -cpu-used 8 -pix_fmt yuv420p -c:a aac -b:a 32k av1-aac.mp4
ffmpeg $Q $V -c:v libaom-av1 -cpu-used 8 -pix_fmt yuv420p10le -an av1-10bit.mp4
ffmpeg $Q $V $A -c:v libx265 -tag:v hvc1 -x265-params log-level=none -c:a aac -b:a 32k hevc-hvc1-aac.mp4
ffmpeg $Q $V -c:v libx265 -tag:v hev1 -x265-params log-level=none -an hevc-hev1.mp4
ffmpeg $Q $V $A -c:v libx264 -profile:v high -level 3.1 -c:a aac -b:a 32k h264-aac.mp4
ffmpeg $Q $V -c:v libx264 -profile:v baseline -level 3.0 -an h264-baseline.mov
ffmpeg $Q $V $A -c:v libvpx-vp9 -c:a libopus -b:a 32k vp9-opus.webm
ffmpeg $Q $V -c:v libaom-av1 -cpu-used 8 -pix_fmt yuv420p -an av1.webm
ffmpeg $Q $V $A -c:v libvpx -c:a libvorbis vp8-vorbis.webm
ffmpeg $Q $A -c:a aac -b:a 32k aac.m4a
ffmpeg $Q $A -c:a libopus -b:a 32k opus.webm
ffmpeg $Q $V -c:v libx264 -an -movflags +faststart h264-faststart.mp4
ffmpeg $Q $V $A -c:v libx264 -c:a aac -b:a 32k h264-aac.mkv
printf 'not a media file' > plain.txt
ls -la

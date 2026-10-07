#!/usr/bin/env bash
# Generates the reference fixtures with ffmpeg: one tiny frame (16×16) and 0.1 s of silence per file.
#
# The fixtures are committed and the tests run against the committed files. Regenerating them is only necessary
# when a new container or codec is added. Profile, level and tier are set explicitly wherever the encoder allows it,
# so that the expected codec strings in MediaTypeDetectorTest stay the same across encoder versions. Whoever
# regenerates the files must still compare the expectations against `ffprobe -show_streams`.
#
# Reference files were generated with ffmpeg N-126188-g426841da9d (2026-08-17) and SVT-AV1 4.2.0.
set -euo pipefail
cd "$(dirname "$0")"
V="-f lavfi -i color=c=red:s=16x16:d=0.1:r=10"
A="-f lavfi -i sine=frequency=440:duration=0.1"
Q="-hide_banner -loglevel error -y"
# AV1: Main profile, main tier; the level follows from the tiny picture size (2.0 = seq_level_idx 0)
ffmpeg $Q $V $A -c:v libsvtav1 -preset 12 -pix_fmt yuv420p -c:a aac -b:a 32k av1-aac.mp4
ffmpeg $Q $V -c:v libsvtav1 -preset 12 -pix_fmt yuv420p10le -an av1-10bit.mp4
ffmpeg $Q $V -c:v libsvtav1 -preset 12 -pix_fmt yuv420p -an av1.webm
# HEVC: Main profile, level 3.0 (level_idc 90), main tier
ffmpeg $Q $V $A -c:v libx265 -tag:v hvc1 -x265-params log-level=none:level-idc=30:no-high-tier=1 -c:a aac -b:a 32k hevc-hvc1-aac.mp4
ffmpeg $Q $V -c:v libx265 -tag:v hev1 -x265-params log-level=none:level-idc=30:no-high-tier=1 -an hevc-hev1.mp4
# H.264: profile and level explicit
ffmpeg $Q $V $A -c:v libx264 -profile:v high -level 3.1 -c:a aac -b:a 32k h264-aac.mp4
ffmpeg $Q $V -c:v libx264 -profile:v high -level 1.0 -an -movflags +faststart h264-faststart.mp4
ffmpeg $Q $V -c:v libx264 -profile:v baseline -level 3.0 -an h264-baseline.mov
ffmpeg $Q $V $A -c:v libx264 -profile:v high -level 1.0 -c:a aac -b:a 32k h264-aac.mkv
# VP9/VP8: profile 0; ffmpeg writes no CodecPrivate for VP9 in WebM, so the short form "vp9" is expected
ffmpeg $Q $V $A -c:v libvpx-vp9 -profile:v 0 -c:a libopus -b:a 32k vp9-opus.webm
ffmpeg $Q $V $A -c:v libvpx -c:a libvorbis vp8-vorbis.webm
# Audio only
ffmpeg $Q $A -c:a aac -b:a 32k aac.m4a
ffmpeg $Q $A -c:a libopus -b:a 32k opus.webm
printf 'not a media file' > plain.txt

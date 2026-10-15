# Header suite

Isolated checks for `Util\ImageHeader`: EXIF orientation, multi-frame detection, alpha and ICC flags, truncation detection and malformed input. Needs ext-gd to build fixtures. No Composer, no database, no environment variables.

```
php tests/header/run.php
```

Expected: `142 passed, 0 failed`

## Cases

- JPEG EXIF orientation: both byte orders, all eight values, the first EXIF segment wins, out-of-range, zero and wrong-typed values, truncated or hostile TIFF headers, and the scan window (`JPEG_SCAN_BYTES`).
- ICC, alpha and frame flags for JPEG, PNG (including APNG and tRNS), GIF, WebP (VP8X flags, lossy and lossless), AVIF, TIFF, BMP and HEIC.
- HEIF container geometry: `irot` and `imir` orientation, `clap` clean aperture with libheif rounding, `colr` profile type, alpha only when an auxiliary item references the primary item, and sequence brands with or without a `meta` box, also beyond the first 64 bytes.
- HEIF hardening: rationals at the 32-bit limits, 2000 random `clap` and `ispe` combinations parse without throwing, declared box sizes beyond the data are rejected without reading more than exists, an oversized `ipma` is capped, and an `ftyp` box that cannot be read completely never counts as "no sequence".
- Truncation detection for JPEG and GIF; PNG truncation is left to the decoder.

The `imir` semantics are checked against ImageMagick in `tests/imagick/run.php`.

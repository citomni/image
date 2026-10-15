# Service suite

End-to-end checks for the `image` service pinned to the GD backend. Imagick and cross-backend parity are in `tests/imagick/run.php`. Writes only below the system temp directory. No Composer, no database, no environment variables.

```
php tests/service/run.php
```

Expected: `209 passed, 0 failed`

This total is for a GD with an AVIF encoder. Without one, the single "AVIF unsupported" case replaces the four AVIF round-trips, so the total is `206 passed, 0 failed`.

## Cases

- Configuration: cfg types, background, `max_pixels` ceiling, AVIF speed range and unknown backends are rejected.
- Inspection: format, stored and display size, flags, bytes and decoder; TIFF and APNG; empty and garbage input; HEIC recognized without any backend, with clean aperture and `irot`.
- HEIF hardening: the pixel budget covers the coded frame, impossible box sizes are rejected without allocating the declared size, and an oversized payload is refused before decoding.
- Capabilities of GD, probed with real samples (round-trips, decode-only GIF, first-frame support, no color management).
- Orientation: display corners for EXIF 1-8 at full size and resized, `auto_orient`, `rotate` after EXIF orientation, and crop in display space.
- Geometry and fits: contain, cover, fill, no implicit upscale, explicit upscale, pixel and WebP size limits.
- Alpha and flattening: JPEG and BMP backgrounds, per-output background, WebP and PNG alpha, GIF and PNG palette transparency through crop and rotate.
- Input policy: multi-frame rejection and the first-frame opt-in, truncation, `max_pixels` from the header, and oversized input never reaching the decoder.
- Capability failures: TIFF decode, GIF and HEIC encode, AVIF.
- Request validation: invalid output specifications, unknown options, empty output list.
- Overlays: anchoring, offset, opacity, clipping, `width_percent` per output, decode, composite and fade counts, handles released on success and failure, sibling independence, alpha bases, overlay orientation, invalid overlays and invisible overlays.
- Metadata stripped, deterministic bytes, progressive JPEG, quality, sibling and order independence, file versus string sources.
- Work counts: one decode per job and one resample per distinct geometry.
- Probes verify output semantics: an encoder that loses alpha is not reported for alpha formats.

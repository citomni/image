# Imagick suite

Checks for the Imagick backend and for GD/Imagick parity. Needs ext-imagick; without it the suite reports one SKIP and passes. No Composer, no database, no environment variables.

```
php tests/imagick/run.php
```

Expected: `178 passed, 0 failed`

This total is for ImageMagick 7.1.1 with encoders for JPEG, PNG, GIF, WebP, AVIF, BMP and TIFF and a HEIC decoder but no HEIC encoder, so the HEIC container cases use the embedded sample. Without ext-imagick the suite reports one SKIP: `0 passed, 0 failed, 1 skipped`.

With other codecs the total differs: every format the runtime encodes adds four round-trip cases, and a runtime without a HEIC decoder reports one SKIP for the HEIC container cases.

## Cases

- Capabilities and round-trips for every format the runtime reports, with file and string sources decoding identically.
- HEIF alpha inspection on real encoder output.
- HEIC clean aperture and container rotation and mirroring, all eight orientations, checked against libheif semantics. The cases use the runtime's HEIC encoder when present and the embedded sample on decode-only builds; the case names say which.
- Decode-only builds: decoders are verified without an encoder.
- Colorspace normalization (YCbCr, CMYK).
- Output semantics only Imagick can exercise: GIF binary transparency, TIFF alpha, BMP flattening.
- First-frame decoding for GIF, TIFF and every format the runtime reports.
- Parity: the same job gives the same geometry, pixels and alpha on GD and Imagick.
- No native Imagick exception crosses the backend seam, and `release()` never throws.
- AVIF small outputs are either exact or an explicit capability failure.

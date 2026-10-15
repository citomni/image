# Color suite

Checks for color management: ICC classification, embedded-profile extraction for every container, the color policy on a runtime without color management (GD), and real conversions on Imagick checked against LittleCMS reference values. Needs ext-gd; the Imagick part needs ext-imagick with verified color management. No Composer, no database, no environment variables.

```
php tests/color/run.php
```

Expected without ext-imagick: `96 passed, 0 failed, 1 skipped`

The Imagick part (section 4 of the script) is reported as one SKIP when ext-imagick is not loaded or ImageMagick has no LittleCMS, and the HEIF nclx cases are reported as one SKIP when the runtime has neither a HEIC nor an AVIF encoder. With ext-imagick and LittleCMS the suite runs those cases too and the total is higher; it depends on the codecs the runtime reports, so record the total of the reference runtime here.

## Cases

1. Classification: known and constructed profiles, malformed and corrupted profiles are `Other`, and 2000 randomly corrupted profiles classify without throwing.
2. Embedded profile extraction for every container.
3. Policy on a runtime without color management (GD): conversion needed under the default policy fails explicitly, `color => ignore` keeps pixel values, sRGB-equivalent profiles need no conversion, unreadable profiles, overlays, a matrix profile with a LUT is never shortcut as sRGB, output color labels are verified after encoding, and declared but non-working color management is not trusted.
4. Color management on Imagick: conversions checked against LittleCMS reference values, output labeled sRGB for every encodable format, PNG and TIFF color chunks, gray with a non-sRGB curve, HEIF nclx color information, routing, overlays, and untagged CMYK.

# Geometry suite

Isolated checks for `Util\Geometry`. No Composer, no database, no environment variables.

```
php tests/geometry/run.php
```

Expected: `161 passed, 0 failed`

Orientation semantics are checked against an independent pixel-grid simulation (clockwise rotation and the two flips of a 7x5 grid), not against `Geometry` itself.

## Cases

- `compose()`: EXIF orientations 1-8 followed by clockwise quarter turns, for 0, 90, 180, 270, 360, -90, -180, -270 and 450 degrees.
- `displaySize()` for every orientation.
- `toStored()`: cropping the stored image and then orienting equals cropping the display, and the target size is right after orientation, for four rectangles per orientation.
- `fit()` with contain (width, height, box, no upscale, upscale, unconstrained, odd rounding, never below one pixel), cover (square, portrait, no upscale keeps the ratio, odd sizes, 1x1) and fill (distorts, clamps per axis, upscale).

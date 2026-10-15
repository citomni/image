# Captcha suite

Checks for the `captchaImage` service: cfg and option validation, codes, PNG output, seeded determinism, glyph placement against real FreeType rendering and answer verification. Needs GD with FreeType. No Composer, no database, no environment variables.

```
php tests/captcha/run.php
```

Expected: `93 passed, 0 failed`

Without FreeType only the service registration and the explicit capability failure are checked, and the rendering cases are reported as one SKIP: `2 passed, 0 failed, 1 skipped`.

## Cases

- Configuration: invalid alphabets, lengths, sizes, colors and fonts are rejected, and the canvas must fit `image.max_pixels`.
- Shipped baseline: the bundled fonts are readable and have ink for every alphabet glyph.
- `code()`: baseline length and alphabet, options, and no render options.
- `create()`: result shape, palette PNG, reported and decoded size, code randomness and alphabet coverage over 200 codes, options, and rejected options (`seed` is never accepted).
- `render()`: seeded determinism (same seed on the same and on a fresh instance), unseeded renders differ, developer codes with symbols and multibyte glyphs, dark background with light ink, and rejected codes and options.
- Placement: for wide and tall glyph codes under the largest warp the service draws, no glyph ink reaches the warp margin or leaves the reported bounds, over 40 seeds per code.
- `verify()`: exact, ASCII case-insensitive and whitespace-insensitive answers, wrong, missing and extra characters, empty expected codes and invalid UTF-8 never verify.

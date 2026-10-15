# Write suite

Filesystem contract checks for `Image::save()`, with the GD backend pinned. Writes only below the system temp directory and removes it at shutdown. No Composer, no database, no environment variables.

```
php tests/write/run.php
```

Expected: `48 passed, 0 failed`

## Cases

- Success: results keyed in caller order, files match the reported geometry, size and backend, no temporary files left, and `saveString()` matches `save()`.
- Overwrite and no-clobber: an existing target is refused by default and the conflict is reported before any decode or probe; `overwrite => true` replaces the target; `overwrite` must be a bool and is not accepted by `encode()`.
- A no-clobber target created concurrently while outputs are produced is detected at publish time, never replaced, and the committed and uncommitted lists are exact.
- Validation happens before any IO: missing directory, duplicate targets (also through `./` and `../`) and a target that is a directory.
- Phase 1 failure (encoder error): no target touched, no temporary files, every handle released. A failing `release()` cannot strand temporary files.
- Encoder verification: wrong encoder output is a capability failure and is never committed.
- Phase 2 failure (commit error): `committed()` and `uncommitted()` list exactly what was replaced and what was left untouched.

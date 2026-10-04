# CitOmni Image

Deterministic, backend-independent image inspection, transformation, compositing, encoding, and file output for CitOmni applications and packages.

`citomni/image` is the shared image-processing capability for the CitOmni ecosystem. It accepts image files or in-memory image bytes, validates the source before expensive work, applies deterministic geometry, composites overlays such as watermarks, produces one or many derived outputs, verifies the encoded result, and exposes the effective runtime capabilities through one App-aware service.

The package deliberately separates **image processing** from upload handling, persistence, storage policy, URLs, and application business logic. It knows pixels, formats, geometry, codecs, and output files. It does not need to know why an image exists.

The public contract is backend-independent. Callers work with source paths or bytes, declarative output specifications, and plain result arrays. Native backend objects never leave the package.

In practical terms, `citomni/image` lets an application turn one source image into a large WebP, a square JPEG thumbnail, or in-memory encoded variants without teaching each consumer the finer points of EXIF orientation, alpha flattening, format limits, truncation checks, or runtime codec support.

---

## Highlights

- **One reusable image service for CitOmni** through `$this->app->image`.
- **Backend-independent public API** with no `\GdImage`, `\Imagick`, or other native engine objects exposed to callers.
- **File and in-memory sources** through paired `inspect*`, `save*`, and `encode*` methods.
- **Multi-output jobs** that decode the source once and reuse identical render geometry across sibling outputs.
- **Deterministic integer geometry** for orientation, rotation, crop, contain, cover, fill, and optional upscaling.
- **Overlays and watermarks** with anchors, offsets, proportional sizing, and opacity, decoded once per job.
- **Two backends, one contract**: GD and Imagick, selected per job by verified capability, with the same public semantics.
- **EXIF orientation 1-8** including mirrored orientations.
- **HEIC/HEIF container geometry** (clean aperture, rotation, mirroring) read without any backend, so iPhone photos inspect and process correctly.
- **ICC color management**: Display P3, Adobe RGB, CMYK, and other profiled sources are converted to sRGB (LittleCMS via ImageMagick), verified at runtime against reference values. A required conversion that is unavailable fails explicitly; it is never skipped silently.
- **Runtime capability verification** through real codec round trips instead of trusting function names or declared format flags alone.
- **Content-based format handling** rather than trusting filenames or caller-supplied MIME labels.
- **Defensive input validation** including pixel limits, truncation checks, decoded/header geometry agreement, and explicit multi-frame policy.
- **Metadata-stripping re-encode path** where encoded output does not retain EXIF, XMP, or GPS metadata.
- **Two-phase file writes** where outputs are fully produced and verified before target files are committed.
- **No-clobber by default**: existing targets are never replaced unless `overwrite` is set, enforced atomically at publish time, not only by an early check.
- **Explicit partial-commit reporting** through `ImageWriteException` and `ImageTargetExistsException`.
- **No hidden format substitution** when a requested codec is unavailable or broken.
- **Low-overhead file processing** where file sources are decoded directly from the file rather than copied into a large PHP string first.

---

## What this package is

`citomni/image` is CitOmni's transport-agnostic image-processing package.

Its job is to provide one stable contract for common image work such as:

- Inspecting image metadata.
- Detecting source format from content.
- Reading stored and display dimensions.
- Applying EXIF orientation.
- Rotating by right angles.
- Cropping in display space.
- Fitting proportionally with `contain`.
- Filling a target box with centered `cover`.
- Explicitly stretching with `fill`.
- Preventing accidental upscaling unless requested.
- Compositing overlays such as logos and watermarks.
- Encoding to supported output formats.
- Flattening transparency for formats without alpha.
- Producing several derived outputs from one source efficiently.
- Writing output files safely.
- Returning encoded bytes without filesystem output.
- Reporting which codecs and backend capabilities are actually usable in the current runtime.

The package uses a fixed public vocabulary for known image formats, while actual decode and encode support is determined at runtime.

Knowing a format is not the same thing as being able to process it. That distinction is intentional.

---

## What this package provides

### Image inspection

`inspect()` and `inspectString()` read container-level metadata without decoding the inspected image's pixels. Resolving the reported `decoder` may run the cached synthetic capability probe the first time a format is seen.

They report:

- Canonical format.
- MIME type.
- Stored width and height.
- EXIF orientation.
- Display width and height after orientation.
- Multi-frame status where it can be determined.
- Alpha/transparency status where it can be determined.
- Whether an ICC profile is present where it can be determined.
- The source color space (`srgb`, `display-p3`, `adobe-rgb`, `rgb`, `gray`, `cmyk`, or `other`).
- Source byte size.
- The first configured backend currently able to decode the format, or `null`.

Inspection is intentionally lighter than a full processing job. It does not promise that the pixel data will decode successfully, and it does not enforce every processing-time guard.

### Image processing

`save()`, `saveString()`, `encode()`, and `encodeString()` process one source into one or many outputs.

Every output may independently define:

- Output format.
- Width.
- Height.
- Fit mode.
- Upscaling policy.
- Explicit crop rectangle.
- Clockwise rotation.
- Overlays (watermarks, logos) drawn onto the fitted output.
- Lossy quality where applicable.
- PNG compression where applicable.
- Flatten background where applicable.

The per-output processing order is fixed:

```text
HEIF container transforms (clean aperture, irot, imir)
-> EXIF orientation
-> explicit rotation
-> explicit crop
-> fit / resize
-> overlays
-> encode
```

The service computes geometry in display space and maps the work back to stored space so expensive orientation can be applied after resampling where possible.

### Runtime capability reporting

`capabilities()` reports the actual runtime rather than an optimistic feature list.

It includes:

- Configured backends.
- Backend availability.
- Backend version.
- Verified decoder per known format.
- Verified encoder per known format.
- Verified first-frame support per known format.
- Color-management capability.

Codec support is probe-verified and memoized per service instance.

### Safe file output

`save()` and `saveString()` use a two-phase write model.

1. Every requested output is written to a temporary sibling file and verified.
2. Only after all outputs pass verification are the temporary files moved into their target paths.

A failure during phase 1 leaves all target files untouched.

Existing targets are not replaced unless an output sets `'overwrite' => true`. See [Write safety model](#write-safety-model).

A failure during the commit phase raises `ImageWriteException` (or `ImageTargetExistsException`), which reports exactly which outputs were committed and which were left untouched.

---

## What this package owns

`citomni/image` owns the mechanics and policy of image processing.

That includes:

- The public `image` service contract.
- Known image format vocabulary.
- Image fit vocabulary.
- Runtime codec capability probing.
- Source header inspection.
- EXIF orientation handling.
- Multi-frame detection and policy.
- Truncation detection where tolerant decoders require an additional guard.
- Pixel-budget enforcement.
- Deterministic image geometry.
- Backend selection.
- Backend-independent output semantics.
- Encode settings and defaults.
- Output verification.
- Two-phase file writes.
- Image-specific exception semantics.

The package owns the image operation, not the application's reason for performing it.

---

## What this package does not own

`citomni/image` is intentionally not a media library, upload system, or persistence layer.

It does **not** own:

- HTTP uploads.
- `$_FILES`.
- Upload error codes.
- Form validation.
- CSRF.
- Database access.
- SQL.
- Repositories for image metadata.
- Entity-to-image relationships.
- Public/private storage policy.
- Object storage or cloud storage.
- URL generation.
- Access control.
- Authorization.
- Remote image fetching.
- OCR.
- PDF rasterization.
- Video processing.
- Application-specific filename policy.
- Application-specific directory layout.

Those responsibilities belong to the caller or another package.

No media-library wizard hat is hidden under `Image.php`.

---

## Relationship to other CitOmni packages

`citomni/image` is a reusable technical capability that can be consumed by both applications and other CitOmni packages.

Typical relationships may look like this:

```text
citomni/kernel
      ↑
citomni/image
      ↑
application or another CitOmni package
```

An HTTP controller may use the service, but `citomni/image` does not depend on `citomni/http`.

A CLI command may use the same service, but `citomni/image` does not depend on `citomni/cli`.

A future upload package may delegate image transformations to `citomni/image`, but image processing remains independently usable outside upload flows.

This is why the service lives in a dedicated package instead of being embedded in an HTTP adapter or one particular application workflow.

---

## Requirements

- PHP **8.5+**
- Composer autoloading
- `citomni/kernel` **^1.0**
- PHP `ext-gd`
- PHP `ext-zlib` (embedded PNG ICC profiles are zlib-compressed)
- Optional: PHP `ext-imagick` (second backend: HEIC, TIFF, GIF output, AVIF where GD lacks it, and color management through ImageMagick's LittleCMS delegate)

GD is the baseline image engine. Imagick is used automatically for jobs GD cannot perform, when it is installed.

Codec support inside both engines still depends on how they were compiled. For example, one server may support AVIF through GD while another PHP 8.5 server with GD may not; an older ImageMagick may decode HEIC but mis-handle very small AVIF images.

Use `capabilities()` when the application needs to know what the current runtime can actually do.

The package does not require `citomni/http` or `citomni/cli`.

---

## Installation

Install the package with Composer:

```bash
composer require citomni/image
```

Register the provider in the application's `config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\Image\Boot\Registry::class,
];
```

The package registers the service in both HTTP and CLI mode:

```php
$this->app->image
```

No routes or CLI commands are contributed by the package.

---

## Quick start

Create a large WebP and a square JPEG thumbnail from one source image:

```php
$files = $this->app->image->save($sourcePath, [
	'large' => [
		'path' => $targetDir . '/large.webp',
		'format' => 'webp',
		'width' => 1600,
	],
	'thumb' => [
		'path' => $targetDir . '/thumb.jpg',
		'format' => 'jpeg',
		'width' => 320,
		'height' => 320,
		'fit' => 'cover',
	],
]);
```

Example result shape:

```php
[
	'large' => [
		'path' => '/absolute/path/large.webp',
		'format' => 'webp',
		'mime' => 'image/webp',
		'width' => 1600,
		'height' => 1067,
		'bytes' => 184321,
		'backend' => 'gd',
	],
	'thumb' => [
		'path' => '/absolute/path/thumb.jpg',
		'format' => 'jpeg',
		'mime' => 'image/jpeg',
		'width' => 320,
		'height' => 320,
		'bytes' => 28412,
		'backend' => 'gd',
	],
]
```

The exact proportional height and encoded byte size naturally depend on the source image and encoder.

---

## Public API

The primary service currently exposes:

```php
$this->app->image->inspect($path);
$this->app->image->inspectString($data);

$this->app->image->save($sourcePath, $outputs, $options);
$this->app->image->saveString($sourceData, $outputs, $options);

$this->app->image->encode($sourcePath, $outputs, $options);
$this->app->image->encodeString($sourceData, $outputs, $options);

$this->app->image->capabilities();
```

The public API uses paths, bytes, declarative arrays, and result arrays.

Native backend objects are internal implementation details.

---

## Inspection

### Inspect a file

```php
$info = $this->app->image->inspect('/path/to/photo.jpg');
```

### Inspect bytes already in memory

```php
$info = $this->app->image->inspectString($imageBytes);
```

The result has this shape:

```php
[
	'format' => 'jpeg',
	'mime' => 'image/jpeg',
	'width' => 4032,
	'height' => 3024,
	'orientation' => 6,
	'display_width' => 3024,
	'display_height' => 4032,
	'multi_frame' => false,
	'alpha' => false,
	'icc_profile' => true,
	'color_space' => 'display-p3',
	'bytes' => 2841932,
	'decoder' => 'gd',
]
```

Some header facts may be `null` when the format does not expose them reliably.

`color_space` is always set; untagged images are `srgb`. See [Color management](#color-management).

Inspection is header-oriented. It does not fully decode the source and should not be treated as proof that later processing will succeed.

---

## Saving files

Use `save()` when the source is a file:

```php
$result = $this->app->image->save('/path/to/source.jpg', [
	'preview' => [
		'path' => '/path/to/output/preview.webp',
		'format' => 'webp',
		'width' => 1200,
		'height' => 1200,
		'fit' => 'contain',
	],
]);
```

Use `saveString()` when the source image already exists as bytes:

```php
$result = $this->app->image->saveString($sourceBytes, [
	'preview' => [
		'path' => '/path/to/output/preview.webp',
		'format' => 'webp',
		'width' => 1200,
	],
]);
```

Target directories must already exist.

Target paths must be unique within one call. Uniqueness is checked on a canonical identity: the existing directory is resolved with `realpath()` (so `dir/./a.png` and `dir/a.png` collide), and on Windows the comparison is case-insensitive.

An existing target is never replaced unless the output sets `'overwrite' => true`.

---

## Encoding to memory

Use `encode()` when the source is a file but the result should stay in memory:

```php
$result = $this->app->image->encode('/path/to/source.jpg', [
	'thumb' => [
		'format' => 'webp',
		'width' => 320,
		'height' => 320,
		'fit' => 'cover',
	],
]);

$bytes = $result['thumb']['data'];
```

Use `encodeString()` when both source and output live in memory:

```php
$result = $this->app->image->encodeString($sourceBytes, [
	'thumb' => [
		'format' => 'jpeg',
		'width' => 320,
		'height' => 320,
		'fit' => 'cover',
		'quality' => 80,
	],
]);
```

Encoded result entries contain:

```php
[
	'data' => $encodedBytes,
	'format' => 'jpeg',
	'mime' => 'image/jpeg',
	'width' => 320,
	'height' => 320,
	'bytes' => strlen($encodedBytes),
	'backend' => 'gd',
]
```

---

## Output specifications

Each output is an associative array.

For `save()` and `saveString()`, `path` is required.

For all processing methods, `format` is required.

### `path`

Target file path.

```php
'path' => '/var/app/images/thumb.webp'
```

Requirements:

- Used only by `save()` and `saveString()`.
- Must be a non-empty string.
- The parent directory must already exist.
- Multiple outputs in one call must not target the same file (compared on canonical identity, see above).

### `overwrite`

Whether an existing target may be replaced:

```php
'overwrite' => true
```

Default:

```text
false
```

| Target | `overwrite` false | `overwrite` true |
|---|---|---|
| Does not exist | Written | Written |
| Exists | `ImageTargetExistsException` | Atomically replaced |

`false` is a guarantee, not a best-effort check. Targets are checked before any processing (cheap early failure), and the final publish uses an atomic create-if-absent (a hard link from the verified temporary file). A file that another process creates while the job is running is therefore never replaced; the job fails with `ImageTargetExistsException` instead. See [Write safety model](#write-safety-model).

Used only by `save()` and `saveString()`.

### `format`

Output format as a string or `ImageFormat`.

```php
'format' => 'webp'
```

Known format values:

```text
jpeg
png
gif
webp
avif
bmp
tiff
heic
```

A known format is not automatically an available encoder.

The current runtime must have a configured backend whose codec passes capability verification.

### `width` and `height`

Optional target dimensions in pixels:

```php
'width' => 1200,
'height' => 800,
```

For `contain`, either dimension may be omitted.

If both are omitted, the selected crop region keeps its size.

`cover` and `fill` require both dimensions.

### `fit`

Supported fit modes:

```text
contain
cover
fill
```

Default:

```text
contain
```

#### `contain`

Preserves aspect ratio and keeps the complete selected image inside the target box.

No pixels are cut away.

With only one target dimension, the other dimension is derived proportionally.

#### `cover`

Preserves aspect ratio and fills the target box completely.

Overflow is removed using a centered crop.

#### `fill`

Scales each axis independently to the requested dimensions.

This may distort the image when source and target aspect ratios differ.

Use it only when distortion is intentional.

### `upscale`

Whether the image may be enlarged:

```php
'upscale' => true
```

Default:

```text
false
```

Without upscaling, the service will not enlarge the selected source region merely to satisfy a larger target box.

### `crop`

Explicit crop rectangle in display-space coordinates:

```php
'crop' => [$x, $y, $width, $height]
```

The crop is interpreted after automatic orientation and explicit rotation.

It must lie completely inside the effective image.

### `rotate`

Additional clockwise rotation in degrees:

```php
'rotate' => 90
```

The value must be an integer multiple of 90.

Negative multiples are accepted.

### `overlays`

Images drawn onto the fitted output, in list order (watermarks, logos, badges):

```php
'overlays' => [
	[
		'file' => '/path/to/logo.png',
		'anchor' => 'bottom-right',
		'offset' => [-24, -24],
		'width_percent' => 20,
		'opacity' => 70,
	],
]
```

Each overlay accepts:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `file` | string | - | Overlay source file. Exactly one of `file` or `data`. |
| `data` | string | - | Overlay source bytes. |
| `anchor` | string or `ImageAnchor` | `center` | `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`. |
| `offset` | `[x, y]` | `[0, 0]` | Added after anchoring; `+x` right, `+y` down. |
| `width` | int | native size | Overlay width in pixels; height follows the overlay's aspect ratio. |
| `width_percent` | int 1-100 | native size | Overlay width relative to this output's width. Mutually exclusive with `width`. |
| `opacity` | int 0-100 | `100` | Multiplies the overlay's own alpha. |

Behavior:

- Overlays are composited with source-over blending and respect both the overlay's and the output's alpha.
- Overlays are clipped to the output; an overlay may extend past an edge or be larger than the output.
- `width_percent` makes one overlay definition scale with every output size in a job.
- Overlay sources follow the same input policy and job options as the main source (format detection, pixel budget, truncation, multi-frame policy, orientation).
- Each overlay source is decoded once per job, resampled once per distinct size, and faded once per distinct size and opacity.
- `opacity` 0 draws nothing. Such an overlay's specification is validated (including that a `file` is readable), but its source is never read or decoded and does not influence backend selection.
- On GD, `opacity` below 100 walks the faded overlay's pixels in PHP (roughly 50-100 ms per megapixel of overlay; a typical watermark costs a few milliseconds). Imagick fades natively.
- Flattening for JPEG/BMP happens after compositing, so transparent overlay regions never turn black.

### `quality`

Lossy quality from 0 to 100:

```php
'quality' => 82
```

Applies only to formats that use lossy quality, currently:

```text
jpeg
webp
avif
heic
```

When omitted, the package configuration is used.

A runtime may still lack an encoder for one of these known formats.

### `compression`

PNG compression level from 0 to 9:

```php
'compression' => 6
```

This is zlib compression for a lossless PNG.

It is deliberately separate from lossy image quality.

### `background`

Flatten background for formats without alpha:

```php
'background' => '#ffffff'
```

The value must use `#rrggbb`.

The current package-level output policy flattens JPEG and BMP outputs.

For alpha-preserving formats, `background` is not an output setting.

---

## Job options

Job options apply to the whole processing call.

### `auto_orient`

```php
[
	'auto_orient' => true,
]
```

Default:

```text
true
```

When enabled, the source EXIF orientation is included in the effective transformation.

All eight EXIF orientations are supported, including mirrored variants.

HEIF container transforms (HEIC/AVIF clean aperture, `irot`, `imir`) are part of the image definition and are always applied, regardless of `auto_orient`.

### `multi_frame`

```php
[
	'multi_frame' => 'reject',
]
```

Supported values:

```text
reject
first
```

Default:

```text
reject
```

Multi-frame or animated input is rejected by default rather than silently converted into a still image.

Use:

```php
[
	'multi_frame' => 'first',
]
```

when the caller explicitly wants the first frame.

Actual first-frame support is backend- and format-dependent. If the active runtime cannot perform it reliably, the service raises `ImageCapabilityException`.

---

### `color`

```php
[
	'color' => 'srgb',
]
```

Supported values:

```text
srgb
ignore
```

Default: the `image.color` configuration value, `srgb` unless changed.

- `srgb` converts sources (and overlays) in another color space to sRGB using their ICC profile, so a Display P3 photo keeps its appearance. sRGB and untagged sources need no conversion. A conversion that no available backend can perform fails with `ImageCapabilityException`.
- `ignore` keeps pixel values as they are and discards profiles: a Display P3 photo is then interpreted as sRGB. Use it deliberately, for example on a runtime without color management.

See [Color management](#color-management).

---

## Multiple outputs from one source

One source may produce many independent outputs in the same job:

```php
$outputs = [
	'hero' => [
		'format' => 'webp',
		'width' => 1600,
	],
	'card' => [
		'format' => 'webp',
		'width' => 640,
		'height' => 400,
		'fit' => 'cover',
	],
	'card_jpeg' => [
		'format' => 'jpeg',
		'width' => 640,
		'height' => 400,
		'fit' => 'cover',
	],
	'thumb' => [
		'format' => 'webp',
		'width' => 240,
		'height' => 240,
		'fit' => 'cover',
	],
];

$result = $this->app->image->encode($sourcePath, $outputs);
```

The service sees the complete job before decoding.

That allows it to:

- Decode the source once.
- Group outputs with identical render geometry.
- Resample identical geometry once.
- Encode the shared rendered image separately for each requested format.
- Keep sibling outputs independent of each other's order.

A variant is always planned from the source image contract, not produced by resizing another requested variant.

That costs a little more than a cascading thumbnail chain and buys much better determinism.

---

## Runtime capabilities

Never assume codec support from the PHP version alone.

Use:

```php
$capabilities = $this->app->image->capabilities();
```

The result has this general shape:

```php
[
	'backends' => [
		'gd' => [
			'available' => true,
			'version' => '...',
		],
		'imagick' => [
			'available' => true,
			'version' => 'ImageMagick 6.9.11-60 Q16 ...',
		],
	],
	'decode' => [
		'jpeg' => 'gd',
		'png' => 'gd',
		'gif' => 'gd',
		'webp' => 'gd',
		'avif' => 'imagick',
		'bmp' => 'gd',
		'tiff' => 'imagick',
		'heic' => 'imagick',
	],
	'encode' => [
		// Same format keys, with backend name or null.
	],
	'first_frame' => [
		// Same format keys, with backend name or null.
	],
	'color_management' => 'imagick',
]
```

The values above are illustrative.

`color_management` names the first configured backend whose ICC conversion is verified with a real Display P3 sample, or `null`.

The actual report depends on the current runtime.

The package does not silently replace a requested format with another format when support is missing.

If the caller requests AVIF and no configured backend can encode verified AVIF output, the operation fails explicitly.

---

## Backends

The package ships two backends:

```text
gd       Baseline engine. Fast for JPEG, PNG, WebP, BMP.
imagick  Optional. Adds HEIC, TIFF, GIF output, and AVIF where GD lacks it.
```

The public service contract is not tied to either. The internal backend seam is marked `@internal` and is not a public extension contract.

One job runs on one backend: the first configured backend that can decode every input (source and overlays) and encode every requested output format. With the default order `['gd', 'imagick']`, ordinary JPEG/PNG/WebP work runs on GD and Imagick takes over only when a job needs it.

Output semantics are package rules, not backend properties. Both backends produce the same geometry, orientation, alpha behavior, flattening, metadata stripping, and 8-bit output; only codec bytes differ. The parity is covered by the regression suite.

### Format support by backend

| Format | GD decode | GD encode | Imagick decode | Imagick encode | First frame |
|---|---|---|---|---|---|
| JPEG | Yes | Yes | Yes | Yes | n/a |
| PNG | Yes | Yes | Yes | Yes | GD, Imagick (APNG: default image) |
| GIF | Yes | No | Yes | Yes | GD, Imagick |
| WebP | Build-dependent | Build-dependent | Yes | Yes | Imagick |
| AVIF | Build-dependent | Build-dependent | Build-dependent | Build-dependent | No |
| BMP | Yes | Yes | Yes | Yes | n/a |
| TIFF | No | No | Yes | Yes | Imagick (first page) |
| HEIC | No | No | Build-dependent | Build-dependent | No |

The runtime capability report remains authoritative. A build that lists a format but fails the package's codec probe is treated as unsupported.

### Output rules shared by both backends

- 8 bits per channel.
- No EXIF, XMP, GPS, or ICC metadata.
- JPEG and BMP are flattened onto the resolved `background`.
- GIF output (Imagick): up to 256 colors; pixels less than 50% opaque become fully transparent, all others fully opaque. The threshold is applied explicitly by the package before encoding, not left to the GIF writer, and the capability probe checks both sides of it.
- PNG keeps alpha; TIFF (Imagick) is LZW-compressed and keeps alpha.

### Imagick specifics

- Every read forces the decoder for the format the package detected and decodes frame 0 only, so ImageMagick neither chooses a coder by magic bytes nor loads further frames.
- Files are read through a PHP stream; ImageMagick never parses the path. HEIC and AVIF are the exception (see below).
- Color conversion uses ImageMagick's LittleCMS delegate, applied while decoding and before any other step. Decoded images are also normalized to an RGB working colorspace: ImageMagick 6.9.11 decodes HEIC/AVIF as YCbCr, and without this compositing and encoding would mix color models.
- HEIC and AVIF files are read into memory first on ImageMagick 6 and 7 alike: both HEIF coders ignore the stream and open the file by name (verified with `tests/probes/imagick-heif-stream-probe.php`).
- No native `ImagickException` leaves the backend. Engine failures surface as `ImageInputException`, `ImageCapabilityException`, or `RuntimeException`, with the native exception kept as `getPrevious()`.
- ImageMagick allocates outside PHP's `memory_limit`. Its own resource policy (`policy.xml`) applies, and a Q16 build uses 8 bytes per RGBA pixel.

## Capability verification

`citomni/image` does not consider a codec healthy merely because:

- An extension is loaded.
- A PHP function exists.
- A native library claims to know a format.
- A format constant is defined.

Decode, encode, and first-frame support are verified separately:

- **Decode**: a small embedded canonical sample of the format (`Util/ProbeSamples`, one per format, about 5 KB in total) must identify as the format and decode to its exact geometry. This does not depend on the backend's encoder, so builds that decode a format they cannot encode, typically ImageMagick with libheif but without the x265 encoder, still accept HEIC input and only refuse HEIC output.
- **Encode**: a real encode/decode round trip of a 65x49 probe image with four zones (opaque, 40% opaque, 60% opaque, transparent). An encoder counts as supported only when:
  - The output identifies as the requested format with exact geometry and decodes back to it.
  - Colors survive (catches color-model mistakes in a codec stack).
  - The package's alpha promise holds: for formats that keep alpha, opaque stays opaque, transparent stays transparent, and both partial zones stay partial and ordered (GIF: 40% becomes transparent and 60% opaque); for formats without alpha, transparent areas are flattened onto the background, never black.
- **First frame**: an embedded two-frame sample (red, then blue) must decode to the first frame.

An encoder that writes the right format and size but drops alpha is therefore reported as unsupported for that format instead of silently producing opaque output. A round trip cannot tell encoder from decoder, so alpha or color loss withdraws encode support only.

The same probe logic runs for every backend; backends only supply the probe image and pixel reads.

Processing uses the same verified capability state.

Every encoded output is additionally checked for expected format and exact dimensions.

AVIF and HEIC outputs receive an additional decode verification because some codec stacks can produce container geometry that looks correct while decoded pixel geometry is not. ImageMagick 6.9.11, for example, decodes its own 8x8 AVIF output as 4x5; such an output fails with `ImageCapabilityException` instead of being written.

Capability results are cached per Image service instance.

---

## Source validation

Image input should be treated as untrusted content.

Before expensive processing, the service performs relevant checks such as:

- Content-based format identification.
- Valid dimensions.
- `image.max_pixels`.
- Multi-frame policy.
- Structural truncation checks where a decoder is known to tolerate truncated input.
- Runtime decoder availability.

After decode, the pixel dimensions must agree with the source header.

A mismatch is rejected rather than silently accepted.

For HEIC and AVIF the package parses the container itself (primary item, `ispe`, `clap`, `irot`, `imir`), so inspection, the pixel budget, and the geometry check work without any backend and do not depend on `getimagesize()` support for HEIF.

### Truncated JPEG and GIF

Some image engines can successfully decode truncated JPEG or GIF input.

`citomni/image` therefore performs additional structural checks for these formats before accepting them for processing.

The goal is not to turn the package into a forensic file parser.

The goal is to avoid treating obviously incomplete image data as a successful source merely because a tolerant decoder returned pixels.

---

## Pixel limits

The default package policy is:

```php
'image' => [
	'max_pixels' => 25_000_000,
]
```

The limit applies to source images, overlay sources, and planned outputs.

For a source image:

```text
width × height
```

must stay within the configured budget. For HEIC and AVIF this is the coded frame (what a decoder allocates), not only the clean aperture, which can be much smaller.

The same applies to derived output geometry. The check is computed without multiplying, so it cannot overflow.

HEIC and AVIF data larger than the uncompressed primary image (4 bytes per coded pixel plus 1 MiB of metadata) is rejected before decoding. Such files are pathological, and some engines decode HEIF from memory.

Container sizes and rationals are treated as untrusted integers: HEIF boxes may not extend beyond the actual data, metadata reads are capped at 1 MiB, clean-aperture arithmetic is range-checked, and file reads are clamped to the file size.

Known format-specific dimension limits are also enforced where relevant.

The pixel limit is a guard against unreasonable image buffers and pathological input. It is not a promise that every image below the limit will fit into every possible PHP runtime memory budget.

---

## EXIF orientation

Automatic orientation is enabled by default.

The package handles EXIF orientation values 1 through 8, including mirrored orientations.

Geometry is planned in display space.

This means a caller can think in terms of the image as it should appear rather than the source file's raw stored axes.

Example:

```php
$result = $this->app->image->encode($sourcePath, [
	'portrait' => [
		'format' => 'webp',
		'width' => 800,
		'height' => 1200,
		'fit' => 'cover',
	],
]);
```

If the JPEG source is physically stored sideways with EXIF orientation 6, the resulting geometry is still based on the correctly oriented display image.

### HEIC/HEIF container transforms

HEIC and AVIF store orientation in the container rather than in EXIF. Phones commonly store the coded image sideways plus an `irot` rotation, and encoders add a `clap` clean aperture to remove codec padding.

The package reads these transforms from the container and applies them in the order the HEIF specification defines (clean aperture, rotation, mirroring). `inspect()` reports the clean-aperture size as `width`/`height` and the container rotation/mirroring as `orientation`.

Mirroring follows the HEIF 2nd-edition definition as decoded by libheif and libavif 1.x: `imir` mode 0 flips top-bottom, mode 1 flips left-right. EXIF orientation inside HEIF files is informational per the specification and is ignored.

Whether a backend applies the transforms while decoding (Imagick/libheif does) or the package applies them afterwards (GD) does not change the result.

Other HEIF metadata reported by `inspect()`:

- `multi_frame`: `true` when the `ftyp` box carries any image-sequence brand: `msf1` (generic), `avis` (AV1), `hevc`/`hevx` (HEVC), or `hevm`/`hevs` (layered HEVC). Still-image brands (`heic`, `heix`, `heim`, `heis`, `avif`, `mif1`) do not count. The whole `ftyp` box is read (up to 4 KiB); a truncated, malformed, or larger `ftyp` makes the container invalid instead of silently meaning "no sequence".
- `alpha`: `true` only when an auxiliary item with an alpha `auxC` property is linked to the primary item by an `auxl` reference. An alpha plane belonging to another item in the same container does not count.
- `icc_profile`: `true` when the primary item's `colr` box carries an ICC profile (`prof` or `rICC`); `nclx` color information is not an ICC profile.

---

## Metadata policy

Encoded outputs do not retain source EXIF, XMP, or GPS metadata.

This is deliberate.

Automatic orientation is applied to the pixels instead of requiring the original orientation tag to survive.

That makes derived output safer for ordinary web/application use and avoids accidentally publishing source GPS information.

Color profiles are not metadata in this sense: they are applied (see [Color management](#color-management)), and outputs are written as untagged sRGB.

---

## Color management

The package treats color as part of correct output, not as optional metadata.

### Policy

With the default policy `srgb` (option `color`, configuration `image.color`), every source and overlay ends up as sRGB pixel values:

| Source | Handling |
|---|---|
| Untagged | Assumed sRGB (the web convention). No conversion. |
| sRGB-equivalent ICC profile | No conversion; works on every backend, including GD. |
| Display P3, Adobe RGB, ProPhoto, Rec. 2020, other RGB profiles | Converted to sRGB from the embedded profile. |
| Grayscale profile with a non-sRGB curve | Converted to sRGB. |
| CMYK with an ICC profile | Converted to sRGB from the profile. |
| HEIF `nclx` primaries 1 (BT.709/sRGB), transfer 13 (sRGB) | sRGB. No conversion. |
| HEIF `nclx` primaries 12 (P3, D65), transfer 13 | Display P3: converted with the package's Display P3 profile. |
| HEIF `nclx` with unspecified (2) primaries or transfer | Treated like an untagged image: the missing part is assumed to be sRGB's (ITU-T H.273 leaves unspecified values to the application). |
| HEIF `nclx`, anything else: transfer 1 (BT.709), BT.2020, PQ, HLG, ... | Rejected under `srgb` (`ImageCapabilityException`). BT.709's transfer curve is not sRGB's, so it is not relabeled as sRGB. |
| Unreadable or oversized profile (above 4 MiB), Lab profiles | Rejected under `srgb` (`ImageCapabilityException`). |
| sRGB matrix tags plus a device-to-PCS LUT (`A2B0`-`A2B2`, `D2B0`-`D2B2`) | Not treated as sRGB; converted, because a color engine uses the LUT, not the matrix. |
| Untagged CMYK | Converted by the working-colorspace step without a profile (approximate) under either policy. |

A job whose inputs need conversion runs only on a backend with verified color management; if the first configured backend has none, the next one that does is used. If no backend can convert, the job fails with `ImageCapabilityException` naming the color space and the `ignore` opt-out. The package never pretends to have converted.

With `ignore`, pixel values are used as they are and profiles are discarded.

### Recognizing sRGB

Whether a profile is sRGB-equivalent is decided without a color engine (`Color/IccProfile`): RGB matrix profiles are compared on their D50 colorants and on all three tone curves. Both must match; sRGB colorants with a linear curve (scRGB) are not sRGB. Profiles with a device-to-PCS LUT (`A2B0`-`A2B2`, `D2B0`-`D2B2`) are never assumed to be sRGB, even when their matrix tags look like sRGB, because a color engine uses the LUT instead. The tolerances are derived from independent sRGB profiles (IEC 61966-2.1 derivatives, Ghostscript, LittleCMS, Compact ICC), which differ by at most about 0.0002.

### Conversion

- Engine: LittleCMS through ImageMagick (`Imagick::profileImage()`), applied while decoding, before orientation, geometry, overlays, and encoding.
- Target: sRGB (the CC0 `sRGB-v4.icc` from the Compact ICC Profiles project, shipped in `Color/Profiles`).
- Rendering intent: perceptual. For matrix profiles (Display P3, Adobe RGB, ...) this equals relative colorimetric; for print profiles with perceptual tables (CMYK) it uses them.
- Verified against LittleCMS reference values for Display P3, Adobe RGB, ProPhoto, Rec. 2020, a gamma-1.8 gray profile, and a CMYK print profile.
- The embedded profile's color model must match the pixel data; RGB data with a CMYK profile is rejected with `ImageInputException`.

### Output

Outputs contain sRGB pixel values and never describe another color space:

- No ICC profile is embedded, consistent with the metadata policy. Untagged images are interpreted as sRGB by browsers and other viewers.
- Container-level color fields are written as sRGB or omitted: HEIC/AVIF CICP is set to sRGB (1/13/6, full range) and the source's CICP is not carried over; TIFF chromaticities are BT.709/D65, never a source's PNG `cHRM`. ImageMagick versions that do not know the CICP defines write no CICP or libheif's default, which is accepted only if it is sRGB or unspecified.
- Verified after every encode, like format and geometry: no output embeds an ICC profile, and HEIC/AVIF CICP must be sRGB or unspecified. An output failing this is rejected with `ImageCapabilityException`, never written.
- Normalized by the backend and covered by regression tests, but not parsed at runtime: other format-specific color fields. Imagick writes PNG without any ancillary chunks (no `gAMA`, `cHRM`, `sRGB`, `iCCP`) and TIFF chromaticities as BT.709/D65; GD writes neither.

### Capability

`capabilities()['color_management']` names the first backend whose conversion is verified with a real Display P3 sample (rgb(200, 60, 60) in P3 must become rgb(217, 42, 52) in sRGB, the LittleCMS reference). An engine that lists a color delegate but ignores profiles fails this check. GD has no color management.

---

## Alpha and flattening

Alpha-preserving formats keep transparency according to the package's output contract.

Formats without alpha are flattened onto a configured background.

The default background is:

```text
#ffffff
```

An output may override it when the selected output format does not preserve alpha:

```php
[
	'format' => 'jpeg',
	'background' => '#f5f5f5',
]
```

The package normalizes backend-specific transparency state before transformations so palette transparency and hidden color-key behavior do not leak into public output semantics.

---

## Multi-frame and animated images

Multi-frame content is detected where the package can determine it reliably from the source container.

The current header logic covers relevant cases such as:

- GIF animation.
- APNG.
- Animated WebP container flags.
- AVIF sequence branding.
- Multi-page TIFF.

Default behavior is conservative:

```text
reject
```

The caller must explicitly request first-frame rendering.

This prevents an animated image from quietly becoming a still image because a backend happened to expose only one frame.

The package does not currently provide full animation-preserving transformation.

---

## Write safety model

`save()` and `saveString()` separate image production from target commit.

### Phase 1

For every requested output:

- Produce the encoded image.
- Write it to a temporary sibling file.
- Verify format.
- Verify exact dimensions.
- Perform additional AVIF verification where relevant.

If any output fails during this phase:

- Temporary files are removed.
- No target file has been touched.
- The original exception propagates.

Temporary files are removed on every exit before publishing has completed, independently of how the failure arose. Releasing engine resources is best-effort and never throws, so cleanup cannot mask the original failure.

Temporary files are named `.{target}.{random}.tmp` next to their target. Removal is best effort: if the filesystem refuses an unlink, the file stays behind and is safe to delete. This also applies to the temporary name of a no-clobber output after publishing, which is a second hard link to the published file.

### Before phase 1

- Every target directory must exist and be writable.
- Target paths must be unique within the call.
- Every target of an output with `'overwrite' => false` must not exist. Otherwise `ImageTargetExistsException` is raised before any input is read.

### Phase 2

After every output is complete and verified, targets are published:

1. Outputs with `'overwrite' => false` first, in output order. Each is published with an atomic create-if-absent (`link()` from the temporary file, then the temporary name is removed). A target created by another process after the early check is detected here and never replaced.
2. Outputs with `'overwrite' => true` next, in output order, each with an atomic `rename()` over the target.

If publishing fails:

- Remaining temporary files are removed.
- Already published outputs stay published; the package does not delete files it published, because that is not race-safe.
- `ImageTargetExistsException` is raised for a concurrently created no-clobber target, `ImageWriteException` otherwise.

Because no-clobber outputs are published first, a target conflict never leaves an already replaced existing file behind.

Atomic no-clobber publishing requires hard-link support in the target filesystem (ext4, XFS, NTFS, and most others). Without it, no-clobber outputs fail with `ImageWriteException` rather than silently degrading to a racy existence check.

The exception exposes:

```php
$e->committed();
$e->uncommitted();
```

so the caller knows exactly what happened.

### Directory behavior

The package does not create target directory trees automatically.

The caller owns filesystem layout and must ensure the destination directory exists.

That keeps image processing separate from application storage policy.

---

## Errors and exceptions

The package distinguishes bad image input, missing runtime capability, partial file commits, developer misuse, and ordinary runtime/IO failure.

### `ImageInputException`

Use this for image content that is rejected.

Typical causes include:

- Empty image data.
- Unknown image data.
- Unsupported source type.
- Corrupt or structurally truncated input.
- Image dimensions above policy.
- Multi-frame input when first-frame use was not requested.
- Decoded geometry disagreeing with the container header.
- An embedded ICC profile that does not match the pixel data or cannot be applied.

This is the exception most likely to be translated into a validation error when the source came from an untrusted user.

### `ImageCapabilityException`

Signals that the current runtime cannot satisfy the requested image contract.

Typical causes include:

- No configured backend can decode the source format.
- No configured backend can encode a requested output format.
- First-frame decoding is unavailable for the requested source.
- Color conversion is required (policy `srgb`) but no backend provides color management, or the source's color description cannot be applied.
- An encoder claims support but produces output that fails verification, including output labeled with a color space other than sRGB.

The package never silently substitutes another format.

### `ImageWriteException`

Signals that finished outputs could not all be published to their targets.

It exposes:

```php
$e->committed();    // output key => path of targets created or replaced
$e->uncommitted();  // output key => path of untouched targets
```

Remaining temporary files are removed before it is thrown (best effort; see [Write safety model](#write-safety-model)).

If `save()` throws any exception other than `ImageWriteException` (or its subclass), no target file was touched.

### `ImageTargetExistsException`

Extends `ImageWriteException`. Signals that an output with `'overwrite' => false` found its target occupied.

```php
$e->target();       // output key whose target exists
$e->committed();    // empty when detected before processing
$e->uncommitted();
```

When detected before processing, nothing was written. When detected at publish time (another process created the target meanwhile), `committed()` lists only newly created no-clobber targets; no existing file was replaced.

### `InvalidArgumentException`

Used for developer misuse such as:

- Unknown output keys.
- Unknown job options.
- Invalid fit values.
- Invalid dimensions.
- Invalid crop rectangles.
- Invalid rotation.
- Invalid quality or compression settings.
- Invalid overlay specifications.
- Duplicate target paths in one save job.

### `RuntimeException`

Used for ordinary IO or backend failures that are not rejected image content or capability mismatches.

CitOmni's normal fail-fast policy applies.

---

## Configuration

The provider contributes baseline configuration under:

```text
image
```

Current defaults:

```php
'image' => [
	'backends' => ['gd', 'imagick'],

	'max_pixels' => 25_000_000,

	'background' => '#ffffff',

	'color' => 'srgb',

	'jpeg' => [
		'quality' => 82,
		'progressive' => true,
	],

	'png' => [
		'compression' => 6,
	],

	'webp' => [
		'quality' => 80,
	],

	'avif' => [
		'quality' => 60,
		'speed' => 6,
	],

	'heic' => [
		'quality' => 75,
	],
],
```

Host applications may override these values through the normal CitOmni configuration merge.

### `backends`

Ordered list of backend identifiers.

The first available backend able to decode every input and encode every requested output format handles the job. Unavailable backends are skipped.

The built-in value is:

```php
'backends' => ['gd', 'imagick'],
```

Use `['imagick', 'gd']` to prefer Imagick, or `['gd']` to disable it.

The backend list is a list value and should be treated as a replacement when overridden, not as a list to be deep-merged item by item.

### `max_pixels`

Maximum allowed source or output pixel area.

Default:

```text
25,000,000
```

### `background`

Default flatten color for output formats without alpha.

Default:

```text
#ffffff
```

### `color`

Default color policy for every call: `srgb` (convert to sRGB with color management) or `ignore` (keep pixel values). The per-call option `color` overrides it. See [Color management](#color-management).

### JPEG

Defaults:

```php
'jpeg' => [
	'quality' => 82,
	'progressive' => true,
],
```

### PNG

Default lossless zlib compression:

```php
'png' => [
	'compression' => 6,
],
```

### WebP

Default lossy quality:

```php
'webp' => [
	'quality' => 80,
],
```

### AVIF

Defaults:

```php
'avif' => [
	'quality' => 60,
	'speed' => 6,
],
```

These defaults do not make AVIF available.

Actual AVIF support still depends on the active backend and runtime codec verification.

`speed` ranges from 0 (slowest, smallest) to 9 (fastest), the range every backend honors (libaom `cpu-used`, ImageMagick `heic:speed`). It is honored where the encoder supports it.

### HEIC

Default lossy quality:

```php
'heic' => [
	'quality' => 75,
],
```

---

## Backend model

Backends are internal implementation seams.

The public service selects one configured backend that can perform the complete job:

```text
decode source and every overlay source
+
encode every requested output format
```

A single job is not split across several backends.

That keeps one source decode, one pixel model, and one deterministic processing path for the complete output set.

The backend interface is intentionally marked `@internal`.

External applications and packages should depend on:

```php
$this->app->image
```

not on backend classes or native engine handles.

---

## Determinism

For the same source bytes, same options, same configuration, and same relevant backend/runtime versions, the package aims for deterministic processing semantics.

That includes:

- Output geometry.
- Crop behavior.
- Orientation.
- Output format.
- Alpha behavior.
- Metadata stripping.
- Sibling independence.
- Error classification.

Byte-identical output across unrelated codec/library versions is not guaranteed.

Codec implementations are allowed to evolve. Geometry and public semantics are not allowed to become vibes.

---

## Performance notes

Image processing is CPU- and memory-intensive by nature, so the package deliberately avoids unnecessary work.

Current design choices include:

- File sources are decoded directly from their files.
- Header scanning uses bounded file reads rather than loading the complete source into a PHP string.
- One processing job decodes the source once.
- Outputs with identical render geometry share one resample result.
- Different output formats may encode from the same rendered geometry.
- Variants are not cascaded from sibling variants.
- Expensive codec probes are memoized per service instance.
- Large native resources are released as soon as the job no longer needs them.
- Orientation is applied after resampling where the geometry permits it, avoiding unnecessary full-resolution rotation work.
- Overlay sources are decoded once per job and resampled once per distinct overlay size.
- With the default backend order, GD handles ordinary work; Imagick is used only when a job needs it. On a 12 MP JPEG the same three-output job measured roughly 0.8 s on GD and 1.0 s on Imagick in the development environment.

JPEG shrink-on-load (decoding at 1/2, 1/4, or 1/8 scale) is intentionally not used yet: choosing one decode scale for the whole job would make a thumbnail's pixels depend on which larger siblings were requested. It can be added per output scale without breaking sibling independence.

This favors low runtime overhead without making one output depend on which sibling outputs happened to be requested.

---

## Current limitations

The implementation intentionally stays narrower than a full imaging suite.

Not currently provided:

- Preserving or embedding ICC profiles in outputs (outputs are untagged sRGB).
- Targets other than sRGB (for example Display P3 output).
- HDR and wide-gamut HEIF color information (BT.2020, PQ, HLG) and PNG `cICP`; PNG `gAMA`/`cHRM` are not interpreted.
- ICC profiles in BMP files (ignored).
- Full animation-preserving processing.
- GIF output through the GD backend.
- HEIC and TIFF through the GD backend.
- libvips backend.
- JPEG shrink-on-load.
- Crop gravity or focal points other than center for `cover`.
- Arbitrary-angle rotation.
- Automatic target directory creation.
- Remote image fetching.
- OCR or PDF rendering.

Known edge behavior:

- A GIF whose first frame does not cover its logical screen is rejected (decoded geometry differs from the header) on both backends.
- Very small AVIF outputs can fail verification on older ImageMagick builds; they are rejected explicitly, never written with wrong geometry.

The public API is backend-independent so additional engines and capabilities can be introduced without exposing their native objects to callers.

---

## Testing

Run the regression suite with:

```bash
composer test
```

The current Composer test script runs:

```text
tests/geometry_test.php
tests/header_test.php
tests/service_test.php
tests/write_test.php
tests/imagick_test.php
tests/color_test.php
```

`service_test.php` and `write_test.php` pin the GD backend. `imagick_test.php` covers the Imagick backend and GD/Imagick parity, and skips itself when `ext-imagick` is not loaded. Its HEIC container tests use the runtime's HEIC encoder when present and the embedded HEIC sample on decode-only builds. `color_test.php` covers ICC classification, profile extraction for every container, the color policy on GD, and conversions on Imagick checked against LittleCMS reference values; its Imagick part skips without ext-imagick or LittleCMS.

The suite covers areas including:

- Integer geometry.
- EXIF orientation.
- HEIF container transforms (clean aperture, rotation, mirroring), checked against libheif.
- Header inspection.
- Format detection.
- Alpha normalization.
- Pixel limits.
- Truncation handling.
- Multi-frame policy.
- Output validation.
- Codec capability probing.
- Sibling determinism.
- File-vs-string behavior.
- Two-phase writes.
- No-clobber publishing, including a target created concurrently during processing.
- Partial commit reporting.
- Overlays: anchoring, clipping, opacity, alpha, proportional sizing, decode counts.
- Colorspace normalization (YCbCr, CMYK).
- ICC classification (including corrupted profiles), embedded-profile extraction (JPEG multi-segment, PNG, WebP, TIFF, GIF, HEIF), and color conversion against LittleCMS references.
- GD/Imagick parity of geometry, pixels, and alpha.

Diagnostic capability probes live separately under:

```text
tests/probes/
```

They are useful for understanding a concrete server's image stack and are not a replacement for regression tests.

`tests/probes/imagick-heif-stream-probe.php` answers one specific question for the current runtime: can ImageMagick decode HEIC/AVIF from a PHP stream? Neither ImageMagick 6 nor 7 can (verified on 6.9.12 and 7.1.1: the HEIF coder opens the file by name), which is why the Imagick backend reads HEIF files into memory first.

### Running the suite on a server without shell access

`tests/probes/run-tests.php` runs the real regression scripts through the web server, so the server's actual GD/ImageMagick build is tested through the package code:

1. Upload the package's `src/` and `tests/` directories (tests are not part of the Composer dist archive) to a non-public or temporary web directory.
2. Set `RUNNER_ENABLED` to `true` in the runner.
3. Open `run-tests.php?test=imagick` (or `geometry`, `header`, `service`, `write`).
4. Set `RUNNER_ENABLED` back to `false` and delete the uploaded files.

The runner refuses to run while disabled, accepts only the five known test names, and prints the failing check with its location.

For syntax validation during development:

```cmd
for /r src %f in (*.php) do @php -l "%f"
for /r tests %f in (*.php) do @php -l "%f"
```

On Windows `.cmd` or `.bat` files, use `%%f` instead of `%f`.

---

## Internal structure

Current source layout:

```text
src/
├── Backend/
│   ├── GdBackend.php
│   ├── ImageBackend.php
│   └── ImagickBackend.php
├── Boot/
│   └── Registry.php
├── Color/
│   ├── IccProfile.php
│   └── Profiles.php
├── Enum/
│   ├── ColorSpace.php
│   ├── ImageAnchor.php
│   ├── ImageFit.php
│   └── ImageFormat.php
├── Exception/
│   ├── ImageCapabilityException.php
│   ├── ImageInputException.php
│   ├── ImageTargetExistsException.php
│   └── ImageWriteException.php
├── Service/
│   └── Image.php
└── Util/
    ├── Geometry.php
    ├── ImageHeader.php
    └── ProbeSamples.php
```

The boundaries are intentional:

- `Service/Image.php` owns the public App-facing contract, validation, planning, backend selection, capability gating, output verification, and write transaction semantics.
- `Backend/` owns engine-specific pixel operations and codecs.
- `Enum/` owns stable bounded public vocabulary.
- `Exception/` owns package-specific failure semantics.
- `Util/` remains pure and backend-independent.

There is no Repository layer because this package has no SQL.

There are no Controllers or Commands because transport adapters are outside the package's responsibility.

---

## Coding and architectural principles

`citomni/image` follows CitOmni's normal architecture and coding discipline.

In particular:

- PHP 8.5+.
- PSR-4.
- Tabs for indentation.
- K&R brace style.
- PHPDoc and inline comments in English.
- Fail fast on invalid configuration and developer misuse.
- Keep transport concerns out of the package.
- Keep SQL out of the package.
- Keep backend-specific objects behind the internal backend boundary.
- Prefer deterministic behavior over hidden fallback.
- Prefer one clear processing contract over backend-specific public APIs.
- Keep runtime overhead low.
- Do not add abstraction without concrete value.

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:

[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Image** is open-source under the **MIT License**.

See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.

You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.

Do not register or use "citomni" or confusingly similar terms in company names, domains, social handles, or top-level vendor/package names.

For details, see [NOTICE](NOTICE).

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.

<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Image\Service;

use CitOmni\Image\Backend\GdBackend;
use CitOmni\Image\Backend\ImageBackend;
use CitOmni\Image\Backend\ImagickBackend;
use CitOmni\Image\Color\IccProfile;
use CitOmni\Image\Color\Profiles;
use CitOmni\Image\Enum\ColorSpace;
use CitOmni\Image\Enum\ImageAnchor;
use CitOmni\Image\Enum\ImageFit;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageInputException;
use CitOmni\Image\Exception\ImageTargetExistsException;
use CitOmni\Image\Exception\ImageWriteException;
use CitOmni\Image\Util\Geometry;
use CitOmni\Image\Util\ImageHeader;
use CitOmni\Image\Util\ProbeSamples;
use CitOmni\Kernel\Service\BaseService;

/**
 * Image: Inspect, transform, composite, encode, and store images behind a backend-independent contract.
 *
 * The public API takes a source (file path or bytes) plus declarative output
 * specifications and returns plain arrays. Native engine objects never leave
 * the package, so backends can be added or swapped without touching callers.
 *
 * Behavior:
 * - inspect()/inspectString() read container headers only; the inspected
 *   image is never pixel-decoded. Resolving "decoder" may run the cached
 *   synthetic capability probe on first use.
 * - save()/saveString() and encode()/encodeString() run one job per call:
 *   1) Validate every output specification and target before touching the
 *      source
 *   2) Read the source and overlay headers and enforce input policy (type,
 *      pixel budget, frames, truncation) before any pixel buffer exists
 *   3) Select the first configured backend that can decode every input and
 *      encode every requested format; fail otherwise, never substitute
 *   4) Decode the source once and each overlay source once
 *   5) Plan each output in display space and resample the stored image
 *      directly, so orientation is applied to the small result only
 *   6) Resample once per distinct geometry, composite once per distinct
 *      overlay set, encode once per output
 *   7) Verify each encoded output's format and exact dimensions
 * - Pipeline per output, in this fixed order: color conversion (at decode),
 *   container transforms (HEIF clap/irot/imir), EXIF orientation, rotate,
 *   crop, fit, overlays, encode.
 * - An output's result never depends on its sibling outputs or their order.
 * - Encoding discards all metadata (EXIF, XMP, GPS). Outputs without alpha
 *   (jpeg, bmp) are flattened onto the resolved background.
 * - save() is two-phase: all outputs are written to temporary sibling files
 *   and verified, then published. See save() for the exact contract.
 *
 * Notes:
 * - Color: with policy "srgb" (default), sources in another color space are
 *   converted to sRGB from their ICC profile (or HEIF nclx description) while
 *   decoding, on a backend with verified color management; outputs are
 *   untagged sRGB. With "ignore", pixel values are kept and profiles
 *   discarded. Independently, backends normalize the working colorspace
 *   (YCbCr, CMYK, gray) to RGB so all processing uses one color model.
 * - Formats are a fixed vocabulary (ImageFormat); support for each is a
 *   runtime capability, verified by a real round-trip on first use and
 *   reported by capabilities().
 * - No SQL. No transport concerns.
 *
 * Typical usage:
 *   $files = $this->app->image->save($tmpPath, [
 *   	'large' => ['path' => $dir . '/large.webp', 'format' => 'webp', 'width' => 1600],
 *   	'thumb' => ['path' => $dir . '/thumb.jpg', 'format' => 'jpeg', 'width' => 320, 'height' => 320, 'fit' => 'cover'],
 *   ]);
 */
final class Image extends BaseService {

	// Backend identifiers known to this package, in no particular order.
	private const BACKEND_CLASSES = [
		'gd' => GdBackend::class,
		'imagick' => ImagickBackend::class,
	];

	// Upper bound for image.max_pixels. Keeps every width * height product
	// used by the geometry code inside 64-bit integer range.
	private const MAX_PIXELS_CEILING = 2_147_483_647;

	private const OUTPUT_KEYS = [
		'format' => true,
		'width' => true,
		'height' => true,
		'fit' => true,
		'upscale' => true,
		'crop' => true,
		'rotate' => true,
		'overlays' => true,
		'quality' => true,
		'compression' => true,
		'background' => true,
	];

	private const FILE_OUTPUT_KEYS = [
		'path' => true,
		'overwrite' => true,
	];

	private const OVERLAY_KEYS = [
		'file' => true,
		'data' => true,
		'anchor' => true,
		'offset' => true,
		'width' => true,
		'width_percent' => true,
		'opacity' => true,
	];

	private const OPTION_KEYS = [
		'auto_orient' => true,
		'multi_frame' => true,
		'color' => true,
	];

	// Color policies: convert sources to sRGB (color management), or keep pixel values as they are.
	private const COLOR_SRGB = 'srgb';
	private const COLOR_IGNORE = 'ignore';

	// Probe for color management: a Display P3 sample of rgb(200, 60, 60) is
	// rgb(217, 42, 52) in sRGB (LittleCMS reference, perceptual intent).
	private const COLOR_PROBE_EXPECTED = [217, 42, 52];

	// Metadata allowance on top of the uncompressed primary image for HEIF/AVIF.
	private const HEIF_SIZE_ALLOWANCE = 1_048_576;

	private const MULTI_FRAME_REJECT = 'reject';
	private const MULTI_FRAME_FIRST = 'first';

	// Fixed encoder settings for capability probes.
	private const PROBE_SETTINGS = [
		'quality' => 90,
		'compression' => 6,
		'progressive' => false,
		'speed' => 8,
		'background' => [255, 255, 255],
	];

	// Probe sample points (x) in the four probe zones; see ImageBackend::probeImage().
	private const PROBE_OPAQUE_X = 8;
	private const PROBE_PARTIAL_40_X = 24;
	private const PROBE_PARTIAL_60_X = 40;
	private const PROBE_CLEAR_X = 56;

	/** @var array<string, class-string<ImageBackend>> */
	private array $backendClasses = [];

	/** @var list<string> */
	private array $backendOrder = [];

	/** @var array<string, ImageBackend|null> Instantiated backends; null when unavailable. */
	private array $backends = [];

	/** @var array<string, array<string, array{decode:bool, encode:bool}>> Probe results per backend and format. */
	private array $probed = [];

	/** @var array<string, array<string, bool>> First-frame probe results per backend and format. */
	private array $firstFrameProbed = [];

	private int $maxPixels = 0;
	private int $pngCompression = 6;
	private bool $jpegProgressive = true;
	private string $colorPolicy = self::COLOR_SRGB;

	/** @var array<string, bool> Color-management probe results per backend. */
	private array $colorProbed = [];
	private int $avifSpeed = 6;

	/** @var array<string, int> Default lossy quality per format value. */
	private array $qualities = [];

	/** @var array{0:int, 1:int, 2:int} */
	private array $background = [255, 255, 255];


	/**
	 * Load and validate image policy from cfg and backend wiring from options.
	 *
	 * Behavior:
	 * - Reads every image.* cfg value once; no backend is instantiated and no
	 *   codec is probed here.
	 * - Service option "backends" (construction-time wiring) may map backend
	 *   identifiers to implementation classes, overriding the built-in map.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When an image.* cfg value or the backends option is invalid.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->image;

		$classes = $this->options['backends'] ?? [];

		if (!\is_array($classes)) {
			throw new \UnexpectedValueException('Service option "backends" must map backend names to class names.');
		}

		$this->backendClasses = $classes + self::BACKEND_CLASSES;

		$order = $cfg->backends;

		if (!\is_array($order) || $order === [] || !\array_is_list($order)) {
			throw new \UnexpectedValueException('Config "image.backends" must be a non-empty list of backend names.');
		}

		foreach ($order as $name) {
			if (!\is_string($name) || !isset($this->backendClasses[$name])) {
				throw new \UnexpectedValueException('Config "image.backends" contains an unknown backend: ' . \var_export($name, true) . '.');
			}
		}

		$this->backendOrder = \array_values(\array_unique($order));

		$this->maxPixels = self::cfgInt($cfg->max_pixels, 'image.max_pixels', 1, self::MAX_PIXELS_CEILING);
		$this->qualities = [
			ImageFormat::Jpeg->value => self::cfgInt($cfg->jpeg->quality, 'image.jpeg.quality', 0, 100),
			ImageFormat::Webp->value => self::cfgInt($cfg->webp->quality, 'image.webp.quality', 0, 100),
			ImageFormat::Avif->value => self::cfgInt($cfg->avif->quality, 'image.avif.quality', 0, 100),
			ImageFormat::Heic->value => self::cfgInt($cfg->heic->quality, 'image.heic.quality', 0, 100),
		];
		$this->pngCompression = self::cfgInt($cfg->png->compression, 'image.png.compression', 0, 9);
		// 0-9: the range every backend honors (libaom cpu-used; ImageMagick heic:speed).
		$this->avifSpeed = self::cfgInt($cfg->avif->speed, 'image.avif.speed', 0, 9);

		if (!\is_bool($cfg->jpeg->progressive)) {
			throw new \UnexpectedValueException('Config "image.jpeg.progressive" must be a bool.');
		}

		$this->jpegProgressive = $cfg->jpeg->progressive;

		$background = self::parseColor($cfg->background);

		if ($background === null) {
			throw new \UnexpectedValueException('Config "image.background" must be a "#rrggbb" hex color.');
		}

		$this->background = $background;

		if ($cfg->color !== self::COLOR_SRGB && $cfg->color !== self::COLOR_IGNORE) {
			throw new \UnexpectedValueException('Config "image.color" must be "srgb" or "ignore".');
		}

		$this->colorPolicy = $cfg->color;
	}


	// ----------------------------------------------------------------
	// Inspection
	// ----------------------------------------------------------------

	/**
	 * Read image metadata from a file without decoding its pixels.
	 *
	 * Behavior:
	 * - Detects the format from content.
	 * - width/height: the picture as encoded, after HEIF clean-aperture
	 *   cropping and before orientation.
	 * - orientation: what must be applied to width x height to display the
	 *   picture: EXIF orientation (JPEG, TIFF) or HEIF irot/imir (HEIC, AVIF).
	 * - display_width/display_height: the size after orientation.
	 * - "decoder" names the backend that would decode this format, or null.
	 * - color_space: srgb, display-p3, adobe-rgb, rgb, gray, cmyk, or other;
	 *   see ColorSpace. Untagged images are srgb.
	 *
	 * Notes:
	 * - Header-level only: does not check image.max_pixels, truncation, or
	 *   whether the pixel data actually decodes; save()/encode() do.
	 * - Resolving "decoder" may run the backend's synthetic capability probe
	 *   (cached per service instance) the first time a format is seen.
	 * - multi_frame, alpha, and icc_profile are null when the format's header
	 *   does not answer them reliably.
	 *
	 * @param string $path Path to a readable regular file.
	 * @return array{format:string, mime:string, width:int, height:int, orientation:int, display_width:int, display_height:int, multi_frame:bool|null, alpha:bool|null, icc_profile:bool|null, color_space:string, bytes:int, decoder:string|null} Metadata.
	 * @throws \RuntimeException When the file is missing or unreadable.
	 * @throws ImageInputException When the content is not a recognized image.
	 */
	public function inspect(string $path): array {
		return $this->describe($this->readHeader(self::fileSource($path), false));
	}


	/**
	 * Read image metadata from in-memory data without decoding its pixels.
	 *
	 * Behavior:
	 * - Same contract as inspect(); "bytes" is the data length.
	 *
	 * @param string $data Image bytes.
	 * @return array{format:string, mime:string, width:int, height:int, orientation:int, display_width:int, display_height:int, multi_frame:bool|null, alpha:bool|null, icc_profile:bool|null, color_space:string, bytes:int, decoder:string|null} Metadata.
	 * @throws ImageInputException When the data is empty or not a recognized image.
	 */
	public function inspectString(string $data): array {
		return $this->describe($this->readHeader(self::stringSource($data), false));
	}


	// ----------------------------------------------------------------
	// Processing
	// ----------------------------------------------------------------

	/**
	 * Render outputs from an image file and save them to files.
	 *
	 * Output specification (per output key):
	 * - path (string, required): Target file. Its directory must exist.
	 * - overwrite (bool): Replace an existing target. Default false: an
	 *   existing target is never replaced (see "Publishing" below).
	 * - format (string|ImageFormat, required): Output format.
	 * - width, height (int|null): Target box in pixels.
	 * - fit (string|ImageFit): contain (default), cover, or fill. cover and
	 *   fill require both width and height.
	 * - upscale (bool): Allow enlarging. Default false.
	 * - crop (array{0:int, 1:int, 2:int, 3:int}|null): x, y, width, height in
	 *   display space, after orientation and rotate. Must lie inside the image.
	 * - rotate (int): Clockwise degrees, a multiple of 90. Default 0.
	 * - overlays (list): Images drawn onto the fitted output, in list order.
	 *   Each overlay:
	 *   1) file (string) or data (string): the overlay source; exactly one
	 *   2) anchor (string|ImageAnchor): default center
	 *   3) offset (array{0:int, 1:int}): added after anchoring; default [0, 0]
	 *   4) width (int) or width_percent (int 1-100 of the output width): the
	 *      overlay keeps its aspect ratio; default native size
	 *   5) opacity (int 0-100): multiplies the overlay's alpha; default 100;
	 *      0 draws nothing, and the source is then never read
	 *   Overlays are clipped to the output. Overlay sources follow the same
	 *   input policy and job options as the main source.
	 * - quality (int 0-100|null): Lossy formats only; null uses cfg.
	 * - compression (int 0-9|null): png only; null uses cfg.
	 * - background ("#rrggbb"|null): Flatten color for jpeg/bmp; null uses cfg.
	 *
	 * Options:
	 * - auto_orient (bool): Apply EXIF orientation. Default true. HEIF
	 *   container transforms are part of the image and always applied.
	 * - multi_frame (string): "reject" (default) rejects animated or
	 *   multi-page sources; "first" renders the first frame (for APNG, the
	 *   default image).
	 * - color (string): "srgb" (default from cfg image.color) converts sources
	 *   in another color space (Display P3, Adobe RGB, CMYK, ... with a known
	 *   profile) to sRGB with color management; outputs are untagged sRGB.
	 *   "ignore" keeps pixel values as they are and discards profiles.
	 *   Conversion that is required but unavailable fails with
	 *   ImageCapabilityException; it is never skipped silently.
	 *
	 * Publishing:
	 * - Before processing: every target directory must be writable, target
	 *   paths must be unique, and every "overwrite" => false target must not
	 *   exist (ImageTargetExistsException otherwise; nothing touched).
	 * - Phase 1 writes every output to a temporary file in its target
	 *   directory and verifies it. If anything fails, the temporary files are
	 *   removed and the exception propagates; no target file was touched.
	 * - Phase 2 publishes. No-clobber outputs go first, in output order, each
	 *   with an atomic create-if-absent (hard link), so a target created
	 *   concurrently is never replaced. Replacing outputs follow, in output
	 *   order, each with an atomic rename.
	 * - A phase-2 failure removes the remaining temporary files and throws
	 *   ImageTargetExistsException (a concurrent target appeared) or
	 *   ImageWriteException; both report exactly which targets were written.
	 *   Because no-clobber outputs are published first, a conflict never
	 *   leaves a replaced existing file behind.
	 * - Temporary files are named ".{target}.{random}.tmp" next to their
	 *   target. Removal is best effort: if the filesystem refuses an unlink,
	 *   the file stays behind and is safe to delete. That includes the
	 *   temporary name of a no-clobber output after publishing, which is a
	 *   second hard link to the published file.
	 *
	 * Notes:
	 * - An empty $outputs array returns [] without reading the source.
	 * - Atomic no-clobber publishing requires hard-link support in the target
	 *   filesystem; without it, no-clobber outputs fail with
	 *   ImageWriteException rather than degrading to a racy check.
	 *
	 * @param string $sourcePath Readable source file.
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications.
	 * @param array<string, mixed> $options Job options.
	 * @return array<int|string, array{path:string, format:string, mime:string, width:int, height:int, bytes:int, backend:string}> Written files, keyed and ordered as $outputs.
	 * @throws \InvalidArgumentException When an output specification or option is invalid.
	 * @throws ImageInputException When an input is rejected or an output cannot be produced from it within policy.
	 * @throws ImageCapabilityException When no backend can perform the job or an encoder fails verification.
	 * @throws ImageTargetExistsException When a no-clobber target exists.
	 * @throws ImageWriteException When publishing fails part-way.
	 * @throws \RuntimeException On IO or engine failure before publishing (no target touched).
	 */
	public function save(string $sourcePath, array $outputs, array $options = []): array {
		return $this->run(self::fileSource($sourcePath), $outputs, $options, true);
	}


	/**
	 * Render outputs from in-memory image data and save them to files.
	 *
	 * Same contract as save().
	 *
	 * @param string $sourceData Image bytes.
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications (see save()).
	 * @param array<string, mixed> $options Job options (see save()).
	 * @return array<int|string, array{path:string, format:string, mime:string, width:int, height:int, bytes:int, backend:string}> Written files, keyed and ordered as $outputs.
	 * @throws \InvalidArgumentException When an output specification or option is invalid.
	 * @throws ImageInputException When an input is rejected or an output cannot be produced from it within policy.
	 * @throws ImageCapabilityException When no backend can perform the job or an encoder fails verification.
	 * @throws ImageTargetExistsException When a no-clobber target exists.
	 * @throws ImageWriteException When publishing fails part-way.
	 * @throws \RuntimeException On IO or engine failure before publishing (no target touched).
	 */
	public function saveString(string $sourceData, array $outputs, array $options = []): array {
		return $this->run(self::stringSource($sourceData), $outputs, $options, true);
	}


	/**
	 * Render outputs from an image file and return them as bytes.
	 *
	 * Same output specification and options as save(), without "path" and
	 * "overwrite".
	 *
	 * @param string $sourcePath Readable source file.
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications.
	 * @param array<string, mixed> $options Job options.
	 * @return array<int|string, array{data:string, format:string, mime:string, width:int, height:int, bytes:int, backend:string}> Encoded outputs, keyed and ordered as $outputs.
	 * @throws \InvalidArgumentException When an output specification or option is invalid.
	 * @throws ImageInputException When an input is rejected or an output cannot be produced from it within policy.
	 * @throws ImageCapabilityException When no backend can perform the job or an encoder fails verification.
	 * @throws \RuntimeException On IO or engine failure.
	 */
	public function encode(string $sourcePath, array $outputs, array $options = []): array {
		return $this->run(self::fileSource($sourcePath), $outputs, $options, false);
	}


	/**
	 * Render outputs from in-memory image data and return them as bytes.
	 *
	 * Same contract as encode().
	 *
	 * @param string $sourceData Image bytes.
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications.
	 * @param array<string, mixed> $options Job options.
	 * @return array<int|string, array{data:string, format:string, mime:string, width:int, height:int, bytes:int, backend:string}> Encoded outputs, keyed and ordered as $outputs.
	 * @throws \InvalidArgumentException When an output specification or option is invalid.
	 * @throws ImageInputException When an input is rejected or an output cannot be produced from it within policy.
	 * @throws ImageCapabilityException When no backend can perform the job or an encoder fails verification.
	 * @throws \RuntimeException On IO or engine failure.
	 */
	public function encodeString(string $sourceData, array $outputs, array $options = []): array {
		return $this->run(self::stringSource($sourceData), $outputs, $options, false);
	}


	// ----------------------------------------------------------------
	// Capabilities
	// ----------------------------------------------------------------

	/**
	 * Report what the current runtime can actually do.
	 *
	 * Behavior:
	 * - Lists configured backends with availability and version.
	 * - For every known format, names the first configured backend whose codec
	 *   passed a real round-trip probe, or null.
	 * - Probes run at most once per format and backend per service instance;
	 *   the same results gate save()/encode().
	 *
	 * @return array{backends:array<string, array{available:bool, version:string|null}>, decode:array<string, string|null>, encode:array<string, string|null>, first_frame:array<string, string|null>, color_management:string|null} Capability report; color_management names the first backend with verified ICC conversion.
	 */
	public function capabilities(): array {
		$report = ['backends' => [], 'decode' => [], 'encode' => [], 'first_frame' => [], 'color_management' => null];

		foreach ($this->backendOrder as $name) {
			$backend = $this->backend($name);
			$report['backends'][$name] = ['available' => $backend !== null, 'version' => $backend?->version()];

			if ($backend !== null && $report['color_management'] === null && $this->supportsColor($backend)) {
				$report['color_management'] = $name;
			}
		}

		foreach (ImageFormat::cases() as $format) {
			$report['decode'][$format->value] = $this->firstBackend($format, 'decode')?->name();
			$report['encode'][$format->value] = $this->firstBackend($format, 'encode')?->name();
			$report['first_frame'][$format->value] = $this->firstBackend($format, 'first_frame')?->name();
		}

		return $report;
	}


	// ----------------------------------------------------------------
	// Job execution
	// ----------------------------------------------------------------

	/**
	 * Execute one save/encode job.
	 *
	 * @param array{path:string|null, data:string|null} $source Source descriptor.
	 * @param array<int|string, mixed> $outputs Output specifications.
	 * @param array<string, mixed> $options Job options.
	 * @param bool $toFiles True for save(), false for encode().
	 * @return array<int|string, array<string, mixed>> Results keyed as $outputs.
	 */
	private function run(array $source, array $outputs, array $options, bool $toFiles): array {
		// -- 1. Validate the request before touching any input ------------
		[$autoOrient, $firstFrame, $convertColor] = $this->normalizeOptions($options);
		$specs = [];
		$paths = [];
		$overlaySources = [];

		foreach ($outputs as $key => $output) {
			$spec = $this->normalizeOutput($key, $output, $toFiles);

			if ($toFiles) {
				self::assertWritableTarget($spec['path']);
				$identity = self::targetIdentity($spec['path']);

				if (isset($paths[$identity])) {
					throw new \InvalidArgumentException('Outputs "' . $paths[$identity] . '" and "' . $key . '" target the same file: ' . $spec['path']);
				}

				$paths[$identity] = $key;
			}

			foreach ($spec['overlays'] as $overlay) {
				// Invisible overlays are validated as specifications only: never
				// read, decoded, or allowed to influence backend selection.
				if ($overlay['opacity'] > 0) {
					$overlaySources[$overlay['key']] = $overlay['source'];
				}
			}

			$specs[$key] = $spec;
		}

		if ($specs === []) {
			return [];
		}

		if ($toFiles) {
			$this->assertNoClobberTargetsFree($specs);
		}

		// -- 2. Header-level input policy for the source and overlays -----
		$header = $this->acceptColor($this->acceptHeader($this->readHeader($source, true), $firstFrame, 'Image'), $convertColor, 'Image');
		$overlayHeaders = [];

		foreach ($overlaySources as $overlayKey => $overlaySource) {
			$overlayHeaders[$overlayKey] = $this->acceptColor($this->acceptHeader($this->readHeader($overlaySource, true), $firstFrame, 'Overlay'), $convertColor, 'Overlay');
		}

		// -- 3. Backend selection (never substitutes a format) ------------
		$inputs = [[$header['format'], $header['multi_frame'] === true, $header['decode_profile'] !== null ? $header['color_space'] : null]];

		foreach ($overlayHeaders as $overlayHeader) {
			$inputs[] = [$overlayHeader['format'], $overlayHeader['multi_frame'] === true, $overlayHeader['decode_profile'] !== null ? $overlayHeader['color_space'] : null];
		}

		$outputFormats = [];

		foreach ($specs as $spec) {
			$outputFormats[$spec['format']->value] = $spec['format'];
		}

		$backend = $this->selectBackend($inputs, $outputFormats);

		// -- 4. Plan every output in display space ------------------------
		$geometry = $this->sourceGeometry($header, $backend, $autoOrient);
		$groups = [];

		foreach ($specs as $key => $spec) {
			$specs[$key] = $spec = $this->planOutput($key, $spec, $geometry, $overlayHeaders, $autoOrient);
			$groups[$spec['group']][] = $key;
		}

		// -- 5. Decode once, render per geometry, encode per output -------
		$image = $this->decode($backend, $source, $header, $geometry['stored']);
		$overlays = ['decoded' => [], 'sized' => [], 'faded' => []];
		$results = [];
		$temporary = [];
		$published = false;

		try {
			try {
				foreach ($groups as $keys) {
					$first = $specs[$keys[0]];
					$rendered = null;
					$oriented = null;

					/** @var array<string, object> $composites */
					$composites = [];

					try {
						$rendered = $backend->render($image, $first['stored']);
						$oriented = $backend->orient($rendered, $first['orientation'], $rendered !== $image);

						// Free the unoriented copy early; it is no longer needed.
						if ($oriented !== $rendered && $rendered !== $image) {
							$backend->release($rendered);
						}

						$rendered = null;

						foreach ($keys as $key) {
							$spec = $specs[$key];
							$canvas = $oriented;

							if ($spec['overlays'] !== []) {
								$composites[$spec['overlay_signature']] ??= $this->composite($backend, $oriented, $spec['overlays'], $overlaySources, $overlayHeaders, $overlays, $autoOrient);
								$canvas = $composites[$spec['overlay_signature']];
							}

							if ($toFiles) {
								$temporary[$key] = self::temporaryPath($spec['path']);
								$backend->write($canvas, $spec['format'], $spec['settings'], $temporary[$key]);
								$this->verifyOutput($backend, $spec, $temporary[$key], null);
								$results[$key] = $this->result($backend, $spec, ['path' => $spec['path']], self::fileSize($temporary[$key]));
							} else {
								$data = $backend->encode($canvas, $spec['format'], $spec['settings']);
								$this->verifyOutput($backend, $spec, null, $data);
								$results[$key] = $this->result($backend, $spec, ['data' => $data], \strlen($data));
							}
						}
					} finally {
						// Everything this group created, exactly once, on success and on
						// failure. The source is released by releaseAll(). Dropping the
						// references lets engines that free on destruction (GD) return
						// the buffers now rather than at the end of the job.
						self::releaseHandles($backend, [...\array_values($composites), $oriented, $rendered], $image);
						$composites = [];
						$oriented = null;
						$rendered = null;
						$canvas = null;
					}
				}
			} finally {
				$this->releaseAll($backend, $image, $overlays);
				$image = null;
				$overlays = ['decoded' => [], 'sized' => [], 'faded' => []];
			}

			// -- 6. Publish (files only) ------------------------------------
			if ($toFiles) {
				$this->publish($specs, $temporary);
			}

			$published = true;
		} finally {
			// Any exit before publishing completed removes the temporary files,
			// however the failure arose. Already published targets are not
			// affected: their temporary names are gone or are spare hard links.
			if (!$published) {
				self::removeFiles($temporary);
			}
		}

		// -- 7. Restore caller order ----------------------------------------

		$ordered = [];

		foreach ($specs as $key => $_) {
			$ordered[$key] = $results[$key];
		}

		return $ordered;
	}


	/**
	 * Fail before any work when a no-clobber target already exists.
	 *
	 * @param array<int|string, array<string, mixed>> $specs Normalized outputs.
	 * @return void
	 * @throws ImageTargetExistsException When a no-clobber target exists.
	 */
	private function assertNoClobberTargetsFree(array $specs): void {
		foreach ($specs as $key => $spec) {
			if (!$spec['overwrite'] && (\file_exists($spec['path']) || \is_link($spec['path']))) {
				throw new ImageTargetExistsException(
					'Output "' . $key . '": target exists and overwrite is false: ' . $spec['path'],
					[],
					\array_map(static fn(array $pending): string => $pending['path'], $specs),
					$key
				);
			}
		}
	}


	/**
	 * Publish verified temporary files to their targets.
	 *
	 * Behavior:
	 * - No-clobber outputs first, in output order, via link() (atomic
	 *   create-if-absent), then the temporary name is removed.
	 * - Replacing outputs next, in output order, via rename() (atomic replace).
	 *
	 * @param array<int|string, array<string, mixed>> $specs Planned outputs.
	 * @param array<int|string, string> $temporary Output key => temporary path.
	 * @return void
	 * @throws ImageTargetExistsException When a no-clobber target appeared concurrently.
	 * @throws ImageWriteException When publishing fails otherwise.
	 */
	private function publish(array $specs, array $temporary): void {
		$order = [];

		foreach ([false, true] as $overwrite) {
			foreach ($specs as $key => $spec) {
				if ($spec['overwrite'] === $overwrite) {
					$order[] = $key;
				}
			}
		}

		$committed = [];

		foreach ($order as $key) {
			$path = $specs[$key]['path'];

			if ($specs[$key]['overwrite']) {
				$published = @\rename($temporary[$key], $path);
			} else {
				$published = @\link($temporary[$key], $path);

				if ($published) {
					@\unlink($temporary[$key]);
				}
			}

			if ($published) {
				$committed[$key] = $path;
				unset($temporary[$key]);
				continue;
			}

			self::removeFiles($temporary);
			$uncommitted = [];

			foreach ($specs as $pendingKey => $pending) {
				if (!isset($committed[$pendingKey])) {
					$uncommitted[$pendingKey] = $pending['path'];
				}
			}

			\clearstatcache(true, $path);

			if (!$specs[$key]['overwrite'] && (\file_exists($path) || \is_link($path))) {
				throw new ImageTargetExistsException(
					'Output "' . $key . '": target was created concurrently and was not replaced: ' . $path,
					$committed,
					$uncommitted,
					$key
				);
			}

			throw new ImageWriteException(
				$specs[$key]['overwrite']
					? 'Failed to move output "' . $key . '" into place: ' . $path
					: 'Failed to publish output "' . $key . '" without overwriting (the filesystem may not support hard links): ' . $path,
				$committed,
				$uncommitted
			);
		}
	}


	/**
	 * Apply input policy to a header.
	 *
	 * @param array<string, mixed> $header Header facts.
	 * @param bool $firstFrame True when the job opted into first-frame use.
	 * @param string $label "Image" or "Overlay", for messages.
	 * @return array<string, mixed> The same header.
	 * @throws ImageInputException When the input is rejected.
	 */
	private function acceptHeader(array $header, bool $firstFrame, string $label): array {
		// The coded frame is what a decoder allocates; for HEIF it can be far
		// larger than the clean aperture, which always lies inside it.
		$width = $header['coded_width'];
		$height = $header['coded_height'];

		if ($this->exceedsPixelBudget($width, $height)) {
			throw new ImageInputException(\sprintf(
				'%s %s is %dx%d; image.max_pixels allows %d pixels.',
				$label,
				[$width, $height] === [$header['width'], $header['height']] ? 'image' : 'coded frame',
				$width,
				$height,
				$this->maxPixels
			));
		}

		// HEIF/AVIF are decoded from memory by some engines. Compressed data
		// larger than the uncompressed primary image is pathological; refuse it
		// rather than buffer it. Safe: width * height <= max_pixels < 2^31.
		if (
			($header['format'] === ImageFormat::Heic || $header['format'] === ImageFormat::Avif)
			&& $header['bytes'] > $width * $height * 4 + self::HEIF_SIZE_ALLOWANCE
		) {
			throw new ImageInputException($label . ' data is larger than its uncompressed primary image.');
		}

		if ($header['multi_frame'] === true && !$firstFrame) {
			throw new ImageInputException($label . ' has multiple frames; pass option multi_frame => "first" to render the first frame.');
		}

		if ($header['truncated']) {
			throw new ImageInputException($label . ' data is truncated.');
		}

		return $header;
	}


	/**
	 * Apply the color policy to an accepted header.
	 *
	 * Behavior:
	 * - Sets "decode_profile": the ICC profile to convert from while decoding,
	 *   or null when no conversion is needed or the policy is "ignore".
	 * - Policy "srgb" with a non-sRGB source that the package cannot describe
	 *   by a profile (unreadable or oversized profile, unsupported HEIF color
	 *   information) fails explicitly; it is never treated as sRGB silently.
	 * - Untagged CMYK has no profile to honor; it is converted to RGB by the
	 *   working-colorspace normalization (approximate) under either policy.
	 *
	 * @param array<string, mixed> $header Accepted header facts.
	 * @param bool $convert True for policy "srgb".
	 * @param string $label "Image" or "Overlay", for messages.
	 * @return array<string, mixed> Header with "decode_profile".
	 * @throws ImageCapabilityException When conversion is required but impossible.
	 */
	private function acceptColor(array $header, bool $convert, string $label): array {
		/** @var ColorSpace $space */
		$space = $header['color_space'];
		$header['decode_profile'] = null;

		if (!$convert || $space === ColorSpace::Srgb || ($space === ColorSpace::Cmyk && $header['source_profile'] === null)) {
			return $header;
		}

		if ($header['source_profile'] === null) {
			throw new ImageCapabilityException(
				$label . ' uses color space "' . $space->value . '" without a color description the package can apply'
				. ' (unreadable or oversized ICC profile, or unsupported HEIF color information);'
				. ' pass option color => "ignore" to keep its pixel values as they are.'
			);
		}

		$header['decode_profile'] = $header['source_profile'];

		return $header;
	}


	/**
	 * Resolve how a decoded input maps to its display picture on a backend.
	 *
	 * Behavior:
	 * - Backends that apply HEIF container transforms decode the display
	 *   picture directly; only EXIF orientation remains.
	 * - Otherwise the decoded handle is the coded image: the clean aperture
	 *   selects the region, and container plus EXIF orientation apply.
	 *
	 * @param array<string, mixed> $header Header facts.
	 * @param ImageBackend $backend Selected backend.
	 * @param bool $autoOrient Whether EXIF orientation applies.
	 * @return array{stored:array{0:int, 1:int}, region:array{0:int, 1:int, 2:int, 3:int}, orientation:int} Geometry.
	 */
	private function sourceGeometry(array $header, ImageBackend $backend, bool $autoOrient): array {
		$exif = $autoOrient ? $header['orientation'] : 1;

		if ($header['container_orientation'] !== 1 || $header['region'] !== [0, 0, $header['coded_width'], $header['coded_height']]) {
			if ($backend->appliesContainerTransforms($header['format'])) {
				[$width, $height] = Geometry::displaySize($header['width'], $header['height'], $header['container_orientation']);

				return ['stored' => [$width, $height], 'region' => [0, 0, $width, $height], 'orientation' => $exif];
			}
		}

		return [
			'stored' => [$header['coded_width'], $header['coded_height']],
			'region' => $header['region'],
			'orientation' => Geometry::then($header['container_orientation'], $exif),
		];
	}


	/**
	 * Decode an input and check its geometry against the header.
	 *
	 * @param ImageBackend $backend Selected backend.
	 * @param array{path:string|null, data:string|null} $source Source descriptor.
	 * @param array<string, mixed> $header Accepted header facts (format, decode_profile).
	 * @param array{0:int, 1:int} $expected Expected decoded size.
	 * @return object Native handle, converted to sRGB when decode_profile is set.
	 * @throws ImageInputException When decoding or color conversion fails, or the geometry disagrees.
	 */
	private function decode(ImageBackend $backend, array $source, array $header, array $expected): object {
		$image = $source['path'] !== null
			? $backend->decodeFile($source['path'], $header['format'], $header['decode_profile'])
			: $backend->decodeString((string)$source['data'], $header['format'], $header['decode_profile']);

		if ($backend->size($image) !== $expected) {
			$backend->release($image);
			throw new ImageInputException('Decoded geometry differs from the image header.');
		}

		return $image;
	}


	/**
	 * Draw an output's overlays onto a copy of its rendered base.
	 *
	 * @param ImageBackend $backend Selected backend.
	 * @param object $base Rendered, oriented output; not modified.
	 * @param list<array<string, mixed>> $overlays Planned overlays.
	 * @param array<string, array{path:string|null, data:string|null}> $sources Overlay sources by key.
	 * @param array<string, array<string, mixed>> $headers Overlay headers by key.
	 * @param array{decoded:array<string, object>, sized:array<string, object>, faded:array<string, object>} $cache Overlay handles, shared across the job.
	 * @param bool $autoOrient Whether EXIF orientation applies.
	 * @return object New composited handle.
	 */
	private function composite(ImageBackend $backend, object $base, array $overlays, array $sources, array $headers, array &$cache, bool $autoOrient): object {
		$canvas = $base;
		$completed = false;

		try {
			foreach ($overlays as $overlay) {
				$handle = $this->overlayHandle($backend, $overlay, $sources, $headers, $cache, $autoOrient);

				if ($overlay['opacity'] < 100) {
					$fadeKey = $overlay['key'] . '|' . $overlay['width'] . 'x' . $overlay['height'] . '|' . $overlay['opacity'];
					$handle = $cache['faded'][$fadeKey] ??= $backend->fade($handle, $overlay['opacity']);
				}

				$canvas = $backend->composite($canvas, $handle, $overlay['x'], $overlay['y'], $canvas !== $base);
			}

			$completed = true;

			return $canvas;
		} finally {
			// A partially composited canvas is ours to release; the base is not.
			if (!$completed) {
				self::releaseHandles($backend, [$canvas], $base);
			}
		}
	}


	/**
	 * Return an overlay decoded once and resampled once per size.
	 *
	 * @param ImageBackend $backend Selected backend.
	 * @param array<string, mixed> $overlay Planned overlay.
	 * @param array<string, array{path:string|null, data:string|null}> $sources Overlay sources by key.
	 * @param array<string, array<string, mixed>> $headers Overlay headers by key.
	 * @param array{decoded:array<string, object>, sized:array<string, object>, faded:array<string, object>} $cache Overlay handles.
	 * @param bool $autoOrient Whether EXIF orientation applies.
	 * @return object Overlay at its planned size, upright.
	 */
	private function overlayHandle(ImageBackend $backend, array $overlay, array $sources, array $headers, array &$cache, bool $autoOrient): object {
		$key = $overlay['key'];
		$sizeKey = $key . '|' . $overlay['width'] . 'x' . $overlay['height'];

		if (isset($cache['sized'][$sizeKey])) {
			return $cache['sized'][$sizeKey];
		}

		$geometry = $this->sourceGeometry($headers[$key], $backend, $autoOrient);
		$cache['decoded'][$key] ??= $this->decode($backend, $sources[$key], $headers[$key], $geometry['stored']);
		$decoded = $cache['decoded'][$key];

		[$regionX, $regionY, $regionWidth, $regionHeight] = $geometry['region'];
		[$displayWidth, $displayHeight] = Geometry::displaySize($regionWidth, $regionHeight, $geometry['orientation']);
		$stored = Geometry::toStored([0, 0, $displayWidth, $displayHeight, $overlay['width'], $overlay['height']], $geometry['orientation'], $regionWidth, $regionHeight);
		$stored[0] += $regionX;
		$stored[1] += $regionY;

		$rendered = $backend->render($decoded, $stored);

		try {
			$oriented = $backend->orient($rendered, $geometry['orientation'], $rendered !== $decoded);
		} catch (\RuntimeException $e) {
			self::releaseHandles($backend, [$rendered], $decoded);
			throw $e;
		}

		if ($oriented !== $rendered && $rendered !== $decoded) {
			$backend->release($rendered);
		}

		return $cache['sized'][$sizeKey] = $oriented;
	}


	/**
	 * Release the source and every overlay handle exactly once.
	 *
	 * @param ImageBackend $backend Selected backend.
	 * @param object $image Source handle.
	 * @param array{decoded:array<string, object>, sized:array<string, object>, faded:array<string, object>} $overlays Overlay handles.
	 * @return void
	 */
	private function releaseAll(ImageBackend $backend, object $image, array $overlays): void {
		self::releaseHandles($backend, [
			$image,
			...\array_values($overlays['faded']),
			...\array_values($overlays['sized']),
			...\array_values($overlays['decoded']),
		]);
	}


	/**
	 * Release distinct handles exactly once.
	 *
	 * Behavior:
	 * - Skips nulls, duplicates (the same instance listed twice), and $except.
	 * - ImageBackend::release() is best-effort and must not throw, so cleanup
	 *   can never mask the job's primary failure.
	 *
	 * @param ImageBackend $backend Backend owning the handles.
	 * @param list<object|null> $handles Handles to release.
	 * @param object|null $except Handle owned elsewhere; never released here.
	 * @return void
	 */
	private static function releaseHandles(ImageBackend $backend, array $handles, ?object $except = null): void {
		$released = $except !== null ? [\spl_object_id($except) => true] : [];

		foreach ($handles as $handle) {
			if ($handle === null || isset($released[\spl_object_id($handle)])) {
				continue;
			}

			$released[\spl_object_id($handle)] = true;
			$backend->release($handle);
		}
	}


	/**
	 * Resolve geometry, orientation, overlays, and grouping for one output.
	 *
	 * @param int|string $key Output key.
	 * @param array<string, mixed> $spec Normalized output.
	 * @param array{stored:array{0:int, 1:int}, region:array{0:int, 1:int, 2:int, 3:int}, orientation:int} $geometry Source geometry on the selected backend.
	 * @param array<string, array<string, mixed>> $overlayHeaders Overlay headers by key.
	 * @param bool $autoOrient Whether EXIF orientation applies.
	 * @return array<string, mixed> Spec with "orientation", "stored", "width", "height", "overlays", "overlay_signature", "group".
	 * @throws \InvalidArgumentException When the crop lies outside the image.
	 * @throws ImageInputException When the planned output or an overlay exceeds policy or format limits.
	 */
	private function planOutput(int|string $key, array $spec, array $geometry, array $overlayHeaders, bool $autoOrient): array {
		// -- 1. Base geometry ----------------------------------------------
		$effective = Geometry::compose($geometry['orientation'], $spec['rotate']);
		[$regionX, $regionY, $regionWidth, $regionHeight] = $geometry['region'];
		[$displayWidth, $displayHeight] = Geometry::displaySize($regionWidth, $regionHeight, $effective);
		[$cropX, $cropY, $cropWidth, $cropHeight] = $spec['crop'] ?? [0, 0, $displayWidth, $displayHeight];

		if ($cropX > $displayWidth - $cropWidth || $cropY > $displayHeight - $cropHeight) {
			throw new \InvalidArgumentException(\sprintf(
				'Output "%s": crop %dx%d at (%d,%d) lies outside the %dx%d image.',
				$key,
				$cropWidth,
				$cropHeight,
				$cropX,
				$cropY,
				$displayWidth,
				$displayHeight
			));
		}

		$plan = Geometry::fit($cropWidth, $cropHeight, $spec['width'], $spec['height'], $spec['fit'], $spec['upscale']);
		$plan[0] += $cropX;
		$plan[1] += $cropY;
		[, , , , $targetWidth, $targetHeight] = $plan;

		$this->assertOutputSize('Output "' . $key . '"', $targetWidth, $targetHeight, $spec['format']);

		$stored = Geometry::toStored($plan, $effective, $regionWidth, $regionHeight);
		$stored[0] += $regionX;
		$stored[1] += $regionY;

		// -- 2. Overlay geometry --------------------------------------------
		$overlays = [];

		foreach ($spec['overlays'] as $index => $overlay) {
			if ($overlay['opacity'] === 0) {
				// Validated like any overlay, but draws nothing.
				continue;
			}

			$header = $overlayHeaders[$overlay['key']];
			[$nativeWidth, $nativeHeight] = Geometry::displaySize(
				$header['width'],
				$header['height'],
				Geometry::then($header['container_orientation'], $autoOrient ? $header['orientation'] : 1)
			);

			$width = $overlay['width'] ?? ($overlay['width_percent'] !== null
				? \max(1, \intdiv($targetWidth * $overlay['width_percent'] * 2 + 100, 200))
				: null);
			[, , , , $width, $height] = Geometry::fit($nativeWidth, $nativeHeight, $width, null, ImageFit::Contain, true);

			$this->assertOutputSize('Output "' . $key . '" overlay ' . $index, $width, $height, null);

			[$x, $y] = $overlay['anchor']->position($targetWidth, $targetHeight, $width, $height);
			$overlays[] = [
				'key' => $overlay['key'],
				'x' => $x + $overlay['offset'][0],
				'y' => $y + $overlay['offset'][1],
				'width' => $width,
				'height' => $height,
				'opacity' => $overlay['opacity'],
			];
		}

		$spec['orientation'] = $effective;
		$spec['stored'] = $stored;
		$spec['width'] = $targetWidth;
		$spec['height'] = $targetHeight;
		$spec['overlays'] = $overlays;
		$spec['overlay_signature'] = \json_encode($overlays, \JSON_THROW_ON_ERROR);
		$spec['group'] = \implode(',', $stored) . '|' . $effective;

		return $spec;
	}


	/**
	 * Enforce the pixel budget and format limits on a planned size.
	 *
	 * @param string $label Subject for messages.
	 * @param int $width Planned width.
	 * @param int $height Planned height.
	 * @param ImageFormat|null $format Output format, or null for intermediate images.
	 * @return void
	 * @throws ImageInputException When the size exceeds policy or format limits.
	 */
	private function assertOutputSize(string $label, int $width, int $height, ?ImageFormat $format): void {
		if ($this->exceedsPixelBudget($width, $height)) {
			throw new ImageInputException(\sprintf('%s would be %dx%d, above image.max_pixels (%d).', $label, $width, $height, $this->maxPixels));
		}

		$limit = $format?->maxDimension();

		if ($limit !== null && \max($width, $height) > $limit) {
			throw new ImageInputException(\sprintf('%s would be %dx%d; %s allows at most %d pixels per axis.', $label, $width, $height, $format->value, $limit));
		}
	}


	/**
	 * Whether width x height exceeds image.max_pixels, without computing the product.
	 *
	 * @param int $width Width, >= 1.
	 * @param int $height Height, >= 1.
	 * @return bool True when the area exceeds the budget.
	 */
	private function exceedsPixelBudget(int $width, int $height): bool {
		return $width > \intdiv($this->maxPixels, \max(1, $height));
	}


	/**
	 * Verify an encoded output's format, exact geometry, and color description.
	 *
	 * Behavior:
	 * - Every output: the container's format and picture size must match the
	 *   plan, and it must not describe a color space other than sRGB: no
	 *   embedded ICC profile, and for HEIC/AVIF no CICP other than sRGB or
	 *   unspecified. Outputs hold sRGB pixel values, so any other label would
	 *   misdescribe them.
	 * - AVIF and HEIC: additionally decoded once, because some libheif-based
	 *   encoders write a correct container for wrong pixel geometry.
	 *
	 * @param ImageBackend $backend Backend that encoded the output.
	 * @param array<string, mixed> $spec Planned output.
	 * @param string|null $path Encoded file, or null.
	 * @param string|null $data Encoded bytes, or null.
	 * @return void
	 * @throws ImageCapabilityException When verification fails.
	 */
	private function verifyOutput(ImageBackend $backend, array $spec, ?string $path, ?string $data): void {
		/** @var ImageFormat $format */
		$format = $spec['format'];
		$source = ['path' => $path, 'data' => $data];
		$info = $path !== null ? @\getimagesize($path) : @\getimagesizefromstring((string)$data);
		$length = $path !== null ? self::fileSize($path) : \strlen((string)$data);
		[$identity, $srgb] = $this->withReader($source, static function (\Closure $read) use ($info, $length, $format): array {
			$identity = ImageHeader::identify($read, $info, $length);

			if ($format === ImageFormat::Heic || $format === ImageFormat::Avif) {
				$heif = ImageHeader::heif($read, $length);
				$srgb = $heif !== null
					&& $heif['icc_profile'] === false
					&& ($heif['nclx'] === null || self::resolveColor(null, false, false, $heif['nclx'])[0] === ColorSpace::Srgb);
			} else {
				$srgb = ImageHeader::scan($format, $read, $length)['icc_profile'] !== true;
			}

			return [$identity, $srgb];
		});
		$valid = $identity !== null
			&& $identity['format'] === $format
			&& $identity['width'] === $spec['width']
			&& $identity['height'] === $spec['height'];

		if ($valid && !$srgb) {
			throw new ImageCapabilityException(\sprintf(
				'The %s encoder of backend "%s" labeled the output with a color space other than sRGB.',
				$format->value,
				$backend->name()
			));
		}

		if ($valid && ($format === ImageFormat::Avif || $format === ImageFormat::Heic)) {
			try {
				$decoded = $path !== null ? $backend->decodeFile($path, $format) : $backend->decodeString((string)$data, $format);
			} catch (ImageInputException) {
				// Undecodable own output is an encoder defect, reported below.
				$decoded = null;
			}

			$valid = $decoded !== null && $backend->size($decoded) === [$spec['width'], $spec['height']];

			if ($decoded !== null) {
				$backend->release($decoded);
			}
		}

		if (!$valid) {
			throw new ImageCapabilityException(\sprintf(
				'The %s encoder of backend "%s" produced output that fails verification (expected %dx%d).',
				$format->value,
				$backend->name(),
				$spec['width'],
				$spec['height']
			));
		}
	}


	/**
	 * Build one result entry.
	 *
	 * @param ImageBackend $backend Backend used.
	 * @param array<string, mixed> $spec Planned output.
	 * @param array<string, string> $target ['path' => ...] or ['data' => ...].
	 * @param int $bytes Encoded size.
	 * @return array<string, mixed> Result entry.
	 */
	private function result(ImageBackend $backend, array $spec, array $target, int $bytes): array {
		return $target + [
			'format' => $spec['format']->value,
			'mime' => $spec['format']->mime(),
			'width' => $spec['width'],
			'height' => $spec['height'],
			'bytes' => $bytes,
			'backend' => $backend->name(),
		];
	}


	// ----------------------------------------------------------------
	// Source and header
	// ----------------------------------------------------------------

	/**
	 * Read header facts for a source.
	 *
	 * @param array{path:string|null, data:string|null} $source Source descriptor.
	 * @param bool $checkIntegrity Also detect truncation (reads the whole JPEG).
	 * @return array{format:ImageFormat, coded_width:int, coded_height:int, region:array{0:int, 1:int, 2:int, 3:int}, width:int, height:int, container_orientation:int, orientation:int, multi_frame:bool|null, alpha:bool|null, icc_profile:bool|null, color_space:ColorSpace, source_profile:string|null, truncated:bool, bytes:int} Header facts; width/height are the region size; source_profile is the ICC profile to convert from for a non-sRGB color space, when known.
	 * @throws \RuntimeException When a file source cannot be read.
	 * @throws ImageInputException When the content is not a recognized image.
	 */
	private function readHeader(array $source, bool $checkIntegrity): array {
		if ($source['path'] !== null) {
			$info = @\getimagesize($source['path']);
			$bytes = @\filesize($source['path']);

			if ($bytes === false) {
				throw new \RuntimeException('Failed to read image file size: ' . $source['path']);
			}
		} else {
			$info = @\getimagesizefromstring((string)$source['data']);
			$bytes = \strlen((string)$source['data']);
		}

		return $this->withReader($source, static function (\Closure $read) use ($info, $bytes, $checkIntegrity): array {
			if ($info === false) {
				if (!ImageHeader::isHeic($read, $bytes)) {
					throw new ImageInputException('Unrecognized image data.');
				}

				$format = ImageFormat::Heic;
			} else {
				$type = (int)$info[2];
				$format = ImageFormat::fromImageType($type)
					?? throw new ImageInputException('Unsupported image type: ' . \image_type_to_mime_type($type) . '.');
			}

			if ($format === ImageFormat::Heic || $format === ImageFormat::Avif) {
				$heif = ImageHeader::heif($read, $bytes)
					?? throw new ImageInputException('The ' . $format->value . ' container could not be parsed.');
				$codedWidth = $heif['width'];
				$codedHeight = $heif['height'];
				$region = $heif['region'];
				$containerOrientation = $heif['orientation'];
				$meta = ['orientation' => 1, 'multi_frame' => $heif['multi_frame'], 'alpha' => $heif['alpha'], 'icc_profile' => $heif['icc_profile'], 'icc' => $heif['icc'], 'cmyk' => false];
				$nclx = $heif['nclx'];
			} else {
				$codedWidth = (int)$info[0];
				$codedHeight = (int)$info[1];
				$region = [0, 0, $codedWidth, $codedHeight];
				$containerOrientation = 1;
				$meta = ImageHeader::scan($format, $read, $bytes);
				$nclx = null;
			}

			[$colorSpace, $sourceProfile] = self::resolveColor($meta['icc'], $meta['icc_profile'], $meta['cmyk'], $nclx);
			unset($meta['icc'], $meta['cmyk']);

			if ($codedWidth < 1 || $codedHeight < 1) {
				throw new ImageInputException('Image header reports invalid dimensions.');
			}

			return [
				'format' => $format,
				'coded_width' => $codedWidth,
				'coded_height' => $codedHeight,
				'region' => $region,
				'width' => $region[2],
				'height' => $region[3],
				'container_orientation' => $containerOrientation,
			] + $meta + [
				'color_space' => $colorSpace,
				'source_profile' => $sourceProfile,
				'truncated' => $checkIntegrity && ImageHeader::isTruncated($format, $read),
				'bytes' => $bytes,
			];
		});
	}


	/**
	 * Classify a source's color space and find the profile to convert from.
	 *
	 * Behavior:
	 * - Embedded ICC profile: classified; it is the conversion source unless
	 *   sRGB-equivalent. Profiles of other color models (Lab, ...) or
	 *   malformed ones cannot be applied.
	 * - Profile present but unreadable or above ImageHeader::ICC_LIMIT: Other.
	 * - HEIF nclx (ITU-T H.273 code points): primaries 1 (BT.709/sRGB) with
	 *   transfer 13 (sRGB) are sRGB; primaries 12 (P3, D65) with transfer 13 are
	 *   Display P3 (the shipped Display P3 profile). Unspecified (2) primaries
	 *   or transfer follow the convention for untagged images (sRGB values), as
	 *   H.273 leaves them to the application. Anything else is Other,
	 *   including transfer 1 (BT.709), whose curve is not sRGB's, and BT.2020,
	 *   PQ, and HLG.
	 * - Untagged CMYK data: Cmyk without a profile.
	 * - Nothing: sRGB, the web convention for untagged images.
	 *
	 * @param string|null $icc Embedded profile bytes.
	 * @param bool|null $iccPresent Whether a profile is embedded at all.
	 * @param bool $cmyk Whether the pixel data is CMYK.
	 * @param array{primaries:int, transfer:int, matrix:int}|null $nclx HEIF color information.
	 * @return array{0:ColorSpace, 1:string|null} Color space and conversion source profile.
	 */
	private static function resolveColor(?string $icc, ?bool $iccPresent, bool $cmyk, ?array $nclx): array {
		if ($icc !== null) {
			$space = IccProfile::classify($icc);

			// Other covers malformed profiles and unsupported data spaces (Lab, ...):
			// never handed to an engine.
			return match ($space) {
				ColorSpace::Srgb, ColorSpace::Other => [$space, null],
				default => [$space, $icc],
			};
		}

		if ($iccPresent === true) {
			return [ColorSpace::Other, null];
		}

		if ($nclx !== null) {
			$transfer = $nclx['transfer'];

			$srgbTransfer = $transfer === 13 || $transfer === 2;

			return match (true) {
				($nclx['primaries'] === 1 || $nclx['primaries'] === 2) && $srgbTransfer => [ColorSpace::Srgb, null],
				$nclx['primaries'] === 12 && $srgbTransfer => [ColorSpace::DisplayP3, Profiles::displayP3()],
				default => [ColorSpace::Other, null],
			};
		}

		return $cmyk ? [ColorSpace::Cmyk, null] : [ColorSpace::Srgb, null];
	}


	/**
	 * Build the public inspect() result.
	 *
	 * @param array<string, mixed> $header Header facts.
	 * @return array<string, mixed> Public metadata.
	 */
	private function describe(array $header): array {
		/** @var ImageFormat $format */
		$format = $header['format'];
		$orientation = Geometry::then($header['container_orientation'], $header['orientation']);
		[$displayWidth, $displayHeight] = Geometry::displaySize($header['width'], $header['height'], $orientation);

		return [
			'format' => $format->value,
			'mime' => $format->mime(),
			'width' => $header['width'],
			'height' => $header['height'],
			'orientation' => $orientation,
			'display_width' => $displayWidth,
			'display_height' => $displayHeight,
			'multi_frame' => $header['multi_frame'],
			'alpha' => $header['alpha'],
			'icc_profile' => $header['icc_profile'],
			'color_space' => $header['color_space']->value,
			'bytes' => $header['bytes'],
			'decoder' => $this->firstBackend($format, 'decode')?->name(),
		];
	}


	/**
	 * Run a callback with a byte reader over the source.
	 *
	 * Behavior:
	 * - String sources are read with substr().
	 * - File sources are read through one handle with a 64 KiB block cache,
	 *   closed when the callback returns or throws. Reads are clamped to the
	 *   file size.
	 *
	 * @template T
	 * @param array{path:string|null, data:string|null} $source Source descriptor.
	 * @param \Closure(\Closure(int, int): string): T $callback Consumer of the reader.
	 * @return T Callback result.
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	private function withReader(array $source, \Closure $callback): mixed {
		if ($source['path'] === null) {
			$data = (string)$source['data'];

			return $callback(static fn(int $offset, int $length): string => ($offset >= 0 && $length > 0) ? (string)\substr($data, $offset, $length) : '');
		}

		$handle = @\fopen($source['path'], 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Failed to open image file: ' . $source['path']);
		}

		$stat = \fstat($handle);
		$size = \is_array($stat) ? (int)$stat['size'] : 0;
		$blockStart = -1;
		$block = '';

		// Reads never exceed the file: a declared length in untrusted data
		// cannot make fread() allocate more than the file holds.
		$read = static function (int $offset, int $length) use ($handle, $size, &$blockStart, &$block): string {
			if ($offset < 0 || $length <= 0 || $offset >= $size) {
				return '';
			}

			$length = \min($length, $size - $offset);

			if ($blockStart < 0 || $offset < $blockStart || $offset + $length > $blockStart + \strlen($block)) {
				if (\fseek($handle, $offset) !== 0) {
					return '';
				}

				$chunk = \fread($handle, \min(\max($length, 65_536), $size - $offset));
				$block = $chunk === false ? '' : $chunk;
				$blockStart = $offset;
			}

			return (string)\substr($block, $offset - $blockStart, $length);
		};

		try {
			return $callback($read);
		} finally {
			\fclose($handle);
		}
	}


	/**
	 * Create a validated file source descriptor.
	 *
	 * @param string $path Source file path.
	 * @return array{path:string, data:null} Source descriptor.
	 * @throws \RuntimeException When the file is missing or unreadable.
	 */
	private static function fileSource(string $path): array {
		if (!\is_file($path) || !\is_readable($path)) {
			throw new \RuntimeException('Image file does not exist or is not readable: ' . $path);
		}

		return ['path' => $path, 'data' => null];
	}


	/**
	 * Create a validated string source descriptor.
	 *
	 * @param string $data Image bytes.
	 * @return array{path:null, data:string} Source descriptor.
	 * @throws ImageInputException When the data is empty.
	 */
	private static function stringSource(string $data): array {
		if ($data === '') {
			throw new ImageInputException('Image data is empty.');
		}

		return ['path' => null, 'data' => $data];
	}


	// ----------------------------------------------------------------
	// Backends and capabilities
	// ----------------------------------------------------------------

	/**
	 * Select the first configured backend that can perform the whole job.
	 *
	 * @param list<array{0:ImageFormat, 1:bool, 2:ColorSpace|null}> $inputs Input format, multi-frame flag, and the color space to convert from (null: none) per input (source first).
	 * @param array<string, ImageFormat> $outputs Distinct output formats.
	 * @return ImageBackend Selected backend.
	 * @throws ImageCapabilityException When no single backend qualifies.
	 */
	private function selectBackend(array $inputs, array $outputs): ImageBackend {
		foreach ($this->backendOrder as $name) {
			$backend = $this->backend($name);

			if ($backend === null) {
				continue;
			}

			foreach ($inputs as [$format, $multiFrame, $convertFrom]) {
				if (!$this->supports($backend, $format, $multiFrame ? 'first_frame' : 'decode')) {
					continue 2;
				}

				if ($convertFrom !== null && !$this->supportsColor($backend)) {
					continue 2;
				}
			}

			foreach ($outputs as $format) {
				if (!$this->supports($backend, $format, 'encode')) {
					continue 2;
				}
			}

			return $backend;
		}

		foreach ($inputs as [$format, $multiFrame, $convertFrom]) {
			if ($convertFrom !== null && !$this->anyBackendManagesColor()) {
				throw new ImageCapabilityException(
					'Converting a ' . $convertFrom->value . ' source to sRGB requires color management, which no available backend provides;'
					. ' pass option color => "ignore" to keep its pixel values as they are.'
				);
			}

			if ($this->firstBackend($format, 'decode') === null) {
				throw new ImageCapabilityException('No available backend can decode ' . $format->value . ' images.');
			}

			if ($multiFrame && $this->firstBackend($format, 'first_frame') === null) {
				throw new ImageCapabilityException('No available backend can decode the first frame of a multi-frame ' . $format->value . ' image.');
			}
		}

		foreach ($outputs as $format) {
			if ($this->firstBackend($format, 'encode') === null) {
				throw new ImageCapabilityException('No available backend can encode ' . $format->value . ' images.');
			}
		}

		$decode = \implode(', ', \array_unique(\array_map(static fn(array $input): string => $input[0]->value, $inputs)));
		$color = \array_filter(\array_column($inputs, 2)) !== [] ? ' with color management' : '';

		throw new ImageCapabilityException('No single available backend can decode ' . $decode . $color . ' and encode ' . \implode(', ', \array_keys($outputs)) . '.');
	}


	/**
	 * Return the first configured backend with a verified capability.
	 *
	 * @param ImageFormat $format Format.
	 * @param string $capability "decode", "encode", or "first_frame".
	 * @return ImageBackend|null Backend, or null when none qualifies.
	 */
	private function firstBackend(ImageFormat $format, string $capability): ?ImageBackend {
		foreach ($this->backendOrder as $name) {
			$backend = $this->backend($name);

			if ($backend !== null && $this->supports($backend, $format, $capability)) {
				return $backend;
			}
		}

		return null;
	}


	/**
	 * Whether a backend has a declared and probe-verified capability.
	 *
	 * @param ImageBackend $backend Backend.
	 * @param ImageFormat $format Format.
	 * @param string $capability "decode", "encode", or "first_frame".
	 * @return bool True when supported.
	 */
	private function supports(ImageBackend $backend, ImageFormat $format, string $capability): bool {
		$declared = match ($capability) {
			'decode' => $backend->canDecode($format),
			'encode' => $backend->canEncode($format),
			default => $backend->canDecode($format) && $backend->canDecodeFirstFrame($format),
		};

		if (!$declared) {
			return false;
		}

		if ($capability === 'first_frame') {
			return $this->firstFrameProbed[$backend->name()][$format->value] ??= $this->probeFirstFrame($backend, $format);
		}

		$probe = $this->probed[$backend->name()][$format->value] ??= $this->probe($backend, $format);

		return $probe[$capability];
	}


	/**
	 * Verify a backend's codecs for one format with real data.
	 *
	 * Behavior:
	 * - Decode: an embedded canonical sample (ProbeSamples) must identify as
	 *   the format and decode to its exact geometry. This does not depend on
	 *   the backend's encoder, so decode-only builds (e.g. HEIC without x265)
	 *   are reported correctly. A successful encode round-trip also counts.
	 * - Encode: the backend encodes its probe image (odd size; opaque, 40%,
	 *   60%, and transparent zones), and the result must:
	 *   1) identify as the format with exact geometry and decode to it
	 *   2) keep colors (catches color-model mistakes in codec stacks)
	 *   3) keep the package's alpha promise: for formats that keep alpha,
	 *      opaque stays opaque, transparent stays transparent, partial stays
	 *      partial and ordered (gif: below 50% transparent, otherwise
	 *      opaque); for formats without alpha, transparent areas are
	 *      flattened onto the background, never black
	 * - Codec failures are reported as unsupported, never thrown.
	 *
	 * Notes:
	 * - A round-trip cannot tell encoder from decoder; alpha or color loss
	 *   withdraws encode support only.
	 *
	 * @param ImageBackend $backend Backend.
	 * @param ImageFormat $format Format.
	 * @return array{decode:bool, encode:bool} Verified support.
	 */
	private function probe(ImageBackend $backend, ImageFormat $format): array {
		$result = ['decode' => false, 'encode' => false];

		if (!$backend->canDecode($format)) {
			return $result;
		}

		// -- 1. Decode: canonical sample, independent of the encoder ----------
		$sample = ProbeSamples::get($format);
		$decoded = $sample !== null ? $this->probeDecode($backend, $format, $sample) : null;

		if ($decoded !== null) {
			$backend->release($decoded);
			$result['decode'] = true;
		}

		// -- 2. Encode: full round-trip through the backend's own encoder -----
		if (!$backend->canEncode($format)) {
			return $result;
		}

		try {
			$probe = $backend->probeImage();

			try {
				$data = $backend->encode($probe, $format, self::PROBE_SETTINGS);
			} finally {
				$backend->release($probe);
			}
		} catch (\RuntimeException) {
			return $result;
		}

		$decoded = $this->probeDecode($backend, $format, $data);

		if ($decoded === null) {
			return $result;
		}

		try {
			$result['decode'] = true;
			$result['encode'] = $this->probeSemantics($backend, $format, $decoded);
		} finally {
			$backend->release($decoded);
		}

		return $result;
	}


	/**
	 * Verify first-frame decoding with an embedded two-frame sample.
	 *
	 * Behavior:
	 * - The sample's frames are red then blue; the decoded image must have the
	 *   probe geometry and show the first (red) frame.
	 *
	 * @param ImageBackend $backend Backend.
	 * @param ImageFormat $format Format.
	 * @return bool True when the first frame is decoded correctly.
	 */
	private function probeFirstFrame(ImageBackend $backend, ImageFormat $format): bool {
		$sample = ProbeSamples::multiFrame($format);
		$decoded = $sample !== null ? $this->probeDecode($backend, $format, $sample) : null;

		if ($decoded === null) {
			return false;
		}

		try {
			$pixel = $backend->pixelAt($decoded, \intdiv(ImageBackend::PROBE_WIDTH, 2), \intdiv(ImageBackend::PROBE_HEIGHT, 2));

			return $pixel[0] >= 200 && $pixel[1] <= 55 && $pixel[2] <= 55 && $pixel[3] >= 230;
		} catch (\RuntimeException) {
			return false;
		} finally {
			$backend->release($decoded);
		}
	}


	/**
	 * Decode probe data if it identifies as the format with probe geometry.
	 *
	 * @param ImageBackend $backend Backend.
	 * @param ImageFormat $format Expected format.
	 * @param string $data Encoded probe or sample.
	 * @return object|null Decoded handle (caller releases), or null when verification fails.
	 */
	private function probeDecode(ImageBackend $backend, ImageFormat $format, string $data): ?object {
		$size = [ImageBackend::PROBE_WIDTH, ImageBackend::PROBE_HEIGHT];
		$identity = ImageHeader::identify(
			static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length),
			@\getimagesizefromstring($data),
			\strlen($data)
		);

		if ($identity === null || $identity['format'] !== $format || [$identity['width'], $identity['height']] !== $size) {
			return null;
		}

		try {
			$decoded = $backend->decodeString($data, $format);
		} catch (\RuntimeException) {
			return null;
		}

		try {
			if ($backend->size($decoded) === $size) {
				return $decoded;
			}
		} catch (\RuntimeException) {
			// Fall through to release.
		}

		$backend->release($decoded);

		return null;
	}


	/**
	 * Check color and alpha semantics of a decoded probe round-trip.
	 *
	 * @param ImageBackend $backend Backend.
	 * @param ImageFormat $format Format that was encoded.
	 * @param object $decoded Decoded probe; not released here.
	 * @return bool True when the package's output semantics hold.
	 */
	private function probeSemantics(ImageBackend $backend, ImageFormat $format, object $decoded): bool {
		try {
			$y = \intdiv(ImageBackend::PROBE_HEIGHT, 2);
			$opaque = $backend->pixelAt($decoded, self::PROBE_OPAQUE_X, $y);
			$partial40 = $backend->pixelAt($decoded, self::PROBE_PARTIAL_40_X, $y);
			$partial60 = $backend->pixelAt($decoded, self::PROBE_PARTIAL_60_X, $y);
			$clear = $backend->pixelAt($decoded, self::PROBE_CLEAR_X, $y);
		} catch (\RuntimeException) {
			return false;
		}

		$near = static fn(array $pixel, array $rgb): bool => \abs($pixel[0] - $rgb[0]) <= 48 && \abs($pixel[1] - $rgb[1]) <= 48 && \abs($pixel[2] - $rgb[2]) <= 48;

		if ($opaque[3] < 230 || !$near($opaque, [200, 40, 60])) {
			return false;
		}

		if (!$format->keepsAlpha()) {
			return $clear[3] >= 230 && $near($clear, [255, 255, 255]);
		}

		if ($clear[3] > 25) {
			return false;
		}

		// GIF: binary at 50% (40% -> transparent, 60% -> opaque). Others: both
		// zones stay partial and keep their order (lossy alpha may drift).
		return $format === ImageFormat::Gif
			? $partial40[3] <= 25 && $partial60[3] >= 230
			: $partial40[3] >= 40 && $partial60[3] <= 215 && $partial60[3] > $partial40[3] + 10;
	}


	/**
	 * Whether a backend has declared and probe-verified color management.
	 *
	 * @param ImageBackend $backend Backend.
	 * @return bool True when supported.
	 */
	private function supportsColor(ImageBackend $backend): bool {
		return $this->colorProbed[$backend->name()] ??= $backend->canManageColor() && $this->probeColor($backend);
	}


	/**
	 * Whether any configured, available backend supports color management.
	 *
	 * @return bool True when at least one does.
	 */
	private function anyBackendManagesColor(): bool {
		foreach ($this->backendOrder as $name) {
			$backend = $this->backend($name);

			if ($backend !== null && $this->supportsColor($backend)) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Verify ICC conversion with a real Display P3 sample.
	 *
	 * Behavior:
	 * - Decodes the embedded P3 sample (rgb(200, 60, 60) in Display P3) with
	 *   the shipped Display P3 profile as source and requires the LittleCMS
	 *   reference result in sRGB, rgb(217, 42, 52), within a small tolerance.
	 * - An engine that ignores the profile returns the unconverted values and
	 *   fails the check.
	 *
	 * @param ImageBackend $backend Backend that declares color management.
	 * @return bool True when the conversion is correct.
	 */
	private function probeColor(ImageBackend $backend): bool {
		$sample = ProbeSamples::colorManaged();

		try {
			$decoded = $backend->decodeString($sample, ImageFormat::Png, Profiles::displayP3());
		} catch (\RuntimeException) {
			return false;
		}

		try {
			$pixel = $backend->pixelAt($decoded, \intdiv(ImageBackend::PROBE_WIDTH, 2), \intdiv(ImageBackend::PROBE_HEIGHT, 2));

			foreach (self::COLOR_PROBE_EXPECTED as $channel => $value) {
				if (\abs($pixel[$channel] - $value) > 6) {
					return false;
				}
			}

			return $backend->size($decoded) === [ImageBackend::PROBE_WIDTH, ImageBackend::PROBE_HEIGHT];
		} catch (\RuntimeException) {
			return false;
		} finally {
			$backend->release($decoded);
		}
	}


	/**
	 * Instantiate a configured backend once, if its engine is available.
	 *
	 * @param string $name Backend identifier.
	 * @return ImageBackend|null Backend, or null when unavailable.
	 * @throws \UnexpectedValueException When the wired class is not an ImageBackend.
	 */
	private function backend(string $name): ?ImageBackend {
		if (\array_key_exists($name, $this->backends)) {
			return $this->backends[$name];
		}

		$class = $this->backendClasses[$name];

		if (!\is_string($class) || !\is_a($class, ImageBackend::class, true)) {
			throw new \UnexpectedValueException('Backend "' . $name . '" must be wired to a class implementing ' . ImageBackend::class . '.');
		}

		return $this->backends[$name] = $class::isAvailable() ? new $class() : null;
	}


	// ----------------------------------------------------------------
	// Request normalization
	// ----------------------------------------------------------------

	/**
	 * Validate job options.
	 *
	 * @param array<string, mixed> $options Job options.
	 * @return array{0:bool, 1:bool, 2:bool} Auto-orient, first-frame, and convert-to-sRGB flags.
	 * @throws \InvalidArgumentException When an option is unknown or invalid.
	 */
	private function normalizeOptions(array $options): array {
		$unknown = \array_diff_key($options, self::OPTION_KEYS);

		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unknown option(s): ' . \implode(', ', \array_keys($unknown)) . '.');
		}

		$autoOrient = $options['auto_orient'] ?? true;
		$multiFrame = $options['multi_frame'] ?? self::MULTI_FRAME_REJECT;

		if (!\is_bool($autoOrient)) {
			throw new \InvalidArgumentException('Option "auto_orient" must be a bool.');
		}

		if ($multiFrame !== self::MULTI_FRAME_REJECT && $multiFrame !== self::MULTI_FRAME_FIRST) {
			throw new \InvalidArgumentException('Option "multi_frame" must be "reject" or "first".');
		}

		$color = $options['color'] ?? $this->colorPolicy;

		if ($color !== self::COLOR_SRGB && $color !== self::COLOR_IGNORE) {
			throw new \InvalidArgumentException('Option "color" must be "srgb" or "ignore".');
		}

		return [$autoOrient, $multiFrame === self::MULTI_FRAME_FIRST, $color === self::COLOR_SRGB];
	}


	/**
	 * Validate and normalize one output specification.
	 *
	 * @param int|string $key Output key.
	 * @param mixed $output Output specification.
	 * @param bool $toFiles True when "path" is required and "overwrite" allowed.
	 * @return array<string, mixed> Normalized output with resolved encoder settings.
	 * @throws \InvalidArgumentException When the specification is invalid.
	 * @throws \RuntimeException When an overlay file is missing or unreadable.
	 * @throws ImageInputException When overlay data is empty.
	 */
	private function normalizeOutput(int|string $key, mixed $output, bool $toFiles): array {
		$fail = static fn(string $message): \InvalidArgumentException => new \InvalidArgumentException('Output "' . $key . '": ' . $message);

		if (!\is_array($output)) {
			throw $fail('specification must be an array.');
		}

		$allowed = $toFiles ? self::OUTPUT_KEYS + self::FILE_OUTPUT_KEYS : self::OUTPUT_KEYS;
		$unknown = \array_diff_key($output, $allowed);

		if ($unknown !== []) {
			throw $fail('unknown key(s): ' . \implode(', ', \array_keys($unknown)) . '.');
		}

		// -- 1. Target and format -----------------------------------------
		$path = null;
		$overwrite = false;

		if ($toFiles) {
			$path = $output['path'] ?? null;
			$overwrite = $output['overwrite'] ?? false;

			if (!\is_string($path) || $path === '') {
				throw $fail('"path" must be a non-empty string.');
			}

			if (!\is_bool($overwrite)) {
				throw $fail('"overwrite" must be a bool.');
			}
		}

		$format = $output['format'] ?? null;
		$format = \is_string($format) ? ImageFormat::tryFrom($format) : $format;

		if (!$format instanceof ImageFormat) {
			throw $fail('"format" must be one of: ' . \implode(', ', \array_column(ImageFormat::cases(), 'value')) . '.');
		}

		// -- 2. Geometry --------------------------------------------------
		$width = $output['width'] ?? null;
		$height = $output['height'] ?? null;
		$limit = \min($this->maxPixels, $format->maxDimension() ?? $this->maxPixels);

		foreach (['width' => $width, 'height' => $height] as $name => $value) {
			if ($value !== null && (!\is_int($value) || $value < 1 || $value > $limit)) {
				throw $fail('"' . $name . '" must be null or an int between 1 and ' . $limit . '.');
			}
		}

		$fit = $output['fit'] ?? ImageFit::Contain;
		$fit = \is_string($fit) ? ImageFit::tryFrom($fit) : $fit;

		if (!$fit instanceof ImageFit) {
			throw $fail('"fit" must be contain, cover, or fill.');
		}

		if ($fit !== ImageFit::Contain && ($width === null || $height === null)) {
			throw $fail('fit "' . $fit->value . '" requires both width and height.');
		}

		$upscale = $output['upscale'] ?? false;

		if (!\is_bool($upscale)) {
			throw $fail('"upscale" must be a bool.');
		}

		$crop = $output['crop'] ?? null;

		if ($crop !== null) {
			if (
				!\is_array($crop)
				|| !\array_is_list($crop)
				|| \count($crop) !== 4
				|| \count(\array_filter($crop, 'is_int')) !== 4
				|| $crop[0] < 0
				|| $crop[1] < 0
				|| $crop[2] < 1
				|| $crop[3] < 1
				|| $crop[2] > $this->maxPixels
				|| $crop[3] > $this->maxPixels
			) {
				throw $fail('"crop" must be [x, y, width, height] with x, y >= 0 and width, height >= 1.');
			}
		}

		$rotate = $output['rotate'] ?? 0;

		if (!\is_int($rotate) || $rotate % 90 !== 0) {
			throw $fail('"rotate" must be an int multiple of 90.');
		}

		// -- 3. Overlays --------------------------------------------------
		$overlays = $output['overlays'] ?? [];

		if (!\is_array($overlays) || !\array_is_list($overlays)) {
			throw $fail('"overlays" must be a list.');
		}

		foreach ($overlays as $index => $overlay) {
			$overlays[$index] = $this->normalizeOverlay($fail, $index, $overlay);
		}

		// -- 4. Format-specific settings ----------------------------------
		$quality = $output['quality'] ?? null;
		$compression = $output['compression'] ?? null;
		$background = $output['background'] ?? null;

		if ($quality !== null && (!$format->usesQuality() || !\is_int($quality) || $quality < 0 || $quality > 100)) {
			throw $fail('"quality" applies to lossy formats only and must be an int between 0 and 100.');
		}

		if ($compression !== null && (!$format->usesCompression() || !\is_int($compression) || $compression < 0 || $compression > 9)) {
			throw $fail('"compression" applies to png only and must be an int between 0 and 9.');
		}

		if ($background !== null) {
			$background = $format->keepsAlpha() ? null : self::parseColor($background);

			if ($background === null) {
				throw $fail('"background" applies to formats without alpha only and must be a "#rrggbb" color.');
			}
		}

		return [
			'path' => $path,
			'overwrite' => $overwrite,
			'format' => $format,
			'width' => $width,
			'height' => $height,
			'fit' => $fit,
			'upscale' => $upscale,
			'crop' => $crop,
			'rotate' => $rotate,
			'overlays' => $overlays,
			'settings' => [
				'quality' => $quality ?? ($this->qualities[$format->value] ?? null),
				'compression' => $compression ?? $this->pngCompression,
				'progressive' => $this->jpegProgressive,
				'speed' => $this->avifSpeed,
				'background' => $background ?? $this->background,
			],
		];
	}


	/**
	 * Validate and normalize one overlay specification.
	 *
	 * @param \Closure(string): \InvalidArgumentException $fail Error factory carrying the output key.
	 * @param int $index Overlay position in the list.
	 * @param mixed $overlay Overlay specification.
	 * @return array{key:string, source:array{path:string|null, data:string|null}, anchor:ImageAnchor, offset:array{0:int, 1:int}, width:int|null, width_percent:int|null, opacity:int} Normalized overlay.
	 * @throws \InvalidArgumentException When the specification is invalid.
	 * @throws \RuntimeException When the overlay file is missing or unreadable.
	 * @throws ImageInputException When overlay data is empty.
	 */
	private function normalizeOverlay(\Closure $fail, int $index, mixed $overlay): array {
		$label = 'overlay ' . $index . ': ';

		if (!\is_array($overlay)) {
			throw $fail($label . 'specification must be an array.');
		}

		$unknown = \array_diff_key($overlay, self::OVERLAY_KEYS);

		if ($unknown !== []) {
			throw $fail($label . 'unknown key(s): ' . \implode(', ', \array_keys($unknown)) . '.');
		}

		$file = $overlay['file'] ?? null;
		$data = $overlay['data'] ?? null;

		if (($file === null) === ($data === null) || ($file !== null && (!\is_string($file) || $file === '')) || ($data !== null && !\is_string($data))) {
			throw $fail($label . 'exactly one of "file" (non-empty path) or "data" (bytes) is required.');
		}

		$anchor = $overlay['anchor'] ?? ImageAnchor::Center;
		$anchor = \is_string($anchor) ? ImageAnchor::tryFrom($anchor) : $anchor;

		if (!$anchor instanceof ImageAnchor) {
			throw $fail($label . '"anchor" must be one of: ' . \implode(', ', \array_column(ImageAnchor::cases(), 'value')) . '.');
		}

		$offset = $overlay['offset'] ?? [0, 0];

		if (
			!\is_array($offset)
			|| !\array_is_list($offset)
			|| \count($offset) !== 2
			|| !\is_int($offset[0])
			|| !\is_int($offset[1])
			|| \abs($offset[0]) > $this->maxPixels
			|| \abs($offset[1]) > $this->maxPixels
		) {
			throw $fail($label . '"offset" must be [x, y] with ints within image.max_pixels.');
		}

		$width = $overlay['width'] ?? null;
		$widthPercent = $overlay['width_percent'] ?? null;

		if ($width !== null && $widthPercent !== null) {
			throw $fail($label . '"width" and "width_percent" are mutually exclusive.');
		}

		if ($width !== null && (!\is_int($width) || $width < 1 || $width > $this->maxPixels)) {
			throw $fail($label . '"width" must be an int between 1 and ' . $this->maxPixels . '.');
		}

		if ($widthPercent !== null && (!\is_int($widthPercent) || $widthPercent < 1 || $widthPercent > 100)) {
			throw $fail($label . '"width_percent" must be an int between 1 and 100.');
		}

		$opacity = $overlay['opacity'] ?? 100;

		if (!\is_int($opacity) || $opacity < 0 || $opacity > 100) {
			throw $fail($label . '"opacity" must be an int between 0 and 100.');
		}

		return [
			'key' => $file !== null ? 'file:' . $file : 'data:' . \hash('sha256', $data),
			'source' => $file !== null ? self::fileSource($file) : self::stringSource($data),
			'anchor' => $anchor,
			'offset' => [$offset[0], $offset[1]],
			'width' => $width,
			'width_percent' => $widthPercent,
			'opacity' => $opacity,
		];
	}


	// ----------------------------------------------------------------
	// Pure helpers
	// ----------------------------------------------------------------

	/**
	 * Fail fast unless the target path can be written.
	 *
	 * @param string $path Target path.
	 * @return void
	 * @throws \RuntimeException When the directory is missing or unwritable, or the path is a directory.
	 */
	private static function assertWritableTarget(string $path): void {
		$directory = \dirname($path);

		if (!\is_dir($directory)) {
			throw new \RuntimeException('Target directory does not exist: ' . $directory);
		}

		if (!\is_writable($directory)) {
			throw new \RuntimeException('Target directory is not writable: ' . $directory);
		}

		if (\is_dir($path)) {
			throw new \RuntimeException('Target path is a directory: ' . $path);
		}
	}


	/**
	 * Canonical identity of a target path for duplicate detection.
	 *
	 * Behavior:
	 * - The existing directory is resolved with realpath() (".", "..",
	 *   symlinks, redundant separators); the file name is kept as given.
	 * - On Windows the identity is case-folded (ASCII), matching the default
	 *   case-insensitive filesystem. Elsewhere it is case-sensitive.
	 *
	 * @param string $path Target path whose directory exists.
	 * @return string Identity string.
	 */
	private static function targetIdentity(string $path): string {
		$directory = \realpath(\dirname($path));
		$identity = ($directory !== false ? $directory : \dirname($path)) . \DIRECTORY_SEPARATOR . \basename($path);

		return \PHP_OS_FAMILY === 'Windows' ? \strtolower($identity) : $identity;
	}


	/**
	 * Build a unique hidden temporary path next to a target.
	 *
	 * @param string $path Target path.
	 * @return string Temporary path in the same directory.
	 */
	private static function temporaryPath(string $path): string {
		return \dirname($path) . \DIRECTORY_SEPARATOR . '.' . \basename($path) . '.' . \bin2hex(\random_bytes(6)) . '.tmp';
	}


	/**
	 * Remove files, ignoring ones that are already gone.
	 *
	 * @param array<int|string, string> $paths Files to remove.
	 * @return void
	 */
	private static function removeFiles(array $paths): void {
		foreach ($paths as $path) {
			if (\is_file($path)) {
				@\unlink($path);
			}
		}
	}


	/**
	 * Return a file size after a fresh stat.
	 *
	 * @param string $path File path.
	 * @return int Size in bytes.
	 * @throws \RuntimeException When the size cannot be read.
	 */
	private static function fileSize(string $path): int {
		\clearstatcache(true, $path);
		$size = @\filesize($path);

		if ($size === false) {
			throw new \RuntimeException('Failed to read written image size: ' . $path);
		}

		return $size;
	}


	/**
	 * Parse a "#rrggbb" color.
	 *
	 * @param mixed $value Candidate color.
	 * @return array{0:int, 1:int, 2:int}|null RGB components, or null when invalid.
	 */
	private static function parseColor(mixed $value): ?array {
		if (!\is_string($value) || \preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) !== 1) {
			return null;
		}

		$rgb = (int)\hexdec(\substr($value, 1));

		return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
	}


	/**
	 * Validate an integer cfg value within inclusive bounds.
	 *
	 * @param mixed $value Raw cfg value.
	 * @param string $key Dotted cfg key for error messages.
	 * @param int $min Inclusive lower bound.
	 * @param int $max Inclusive upper bound.
	 * @return int Validated value.
	 * @throws \UnexpectedValueException When invalid.
	 */
	private static function cfgInt(mixed $value, string $key, int $min, int $max): int {
		if (!\is_int($value) || $value < $min || $value > $max) {
			throw new \UnexpectedValueException('Config "' . $key . '" must be an int between ' . $min . ' and ' . $max . '.');
		}

		return $value;
	}


}

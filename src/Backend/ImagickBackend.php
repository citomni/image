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

namespace CitOmni\Image\Backend;

use CitOmni\Image\Color\IccProfile;
use CitOmni\Image\Color\Profiles;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageInputException;

/**
 * Imagick (ImageMagick) implementation of the internal image backend.
 *
 * @internal Used by the Image service only.
 *
 * Behavior:
 * - Every read forces the decoder for the format the Image service detected
 *   ("FMT:" prefix) and reads frame 0 only ("[0]"), so ImageMagick neither
 *   picks a coder by magic bytes nor loads further frames into memory.
 * - Files are read through a PHP stream, so ImageMagick never parses the
 *   path. HEIC and AVIF are read from memory instead: the HEIF coder of
 *   ImageMagick 6 and 7 alike ignores the stream and opens the file by name
 *   (verified on 6.9.12 and 7.1.1).
 * - Decoded images are normalized: forcing filename cleared (it would
 *   otherwise override the output format), single frame, no virtual canvas
 *   offset, sRGB colorspace. ImageMagick 6.9.11's HEIF coder returns YCbCr
 *   pixels; without normalization, compositing and encoding would mix color
 *   models.
 * - HEIF container transforms (clap, irot, imir) are applied by libheif
 *   during decoding.
 * - Encoding strips all metadata and writes 8 bits per channel. Color fields
 *   are written as sRGB: chromaticities reset to BT.709/D65, HEIC/AVIF CICP
 *   1/13/6 with source CICP not preserved.
 * - Color management uses ImageMagick's LittleCMS delegate: with a source
 *   profile, decoded pixels are converted to sRGB (perceptual intent) before
 *   any other processing.
 * - No native Imagick exception leaves this class: every public method runs
 *   engine calls through engine(), which converts them to \RuntimeException;
 *   reads map decoder errors to ImageInputException or
 *   ImageCapabilityException first. release() is best-effort and never
 *   throws.
 *
 * Notes:
 * - ImageMagick allocates outside PHP's memory_limit; its own resource
 *   policy (policy.xml) applies. Q16 builds use 8 bytes per RGBA pixel.
 * - Without a source profile, decoding only normalizes the working
 *   colorspace (YCbCr, CMYK, gray to sRGB) and applies no ICC profile.
 *   Profiles are discarded on encode either way.
 * - Resampling uses the Lanczos filter.
 */
final class ImagickBackend implements ImageBackend {

	private const MAGICK = [
		'jpeg' => 'JPEG',
		'png' => 'PNG',
		'gif' => 'GIF',
		'webp' => 'WEBP',
		'avif' => 'AVIF',
		'bmp' => 'BMP',
		'tiff' => 'TIFF',
		'heic' => 'HEIC',
	];

	// Formats whose HEIF coder ignores the stream and opens the file by name, so
	// the input is passed from memory (verified on ImageMagick 6.9.12 and 7.1.1
	// with tests/probes/imagick-heif-stream-probe.php).
	private const MEMORY_READ = ['avif' => true, 'heic' => true];

	// ImageMagick error severities that mean "this input is not decodable".
	private const INPUT_ERRORS = [
		325 => true, // CorruptImageWarning
		330 => true, // FileOpenWarning
		425 => true, // CorruptImageError
		435 => true, // BlobError
		450 => true, // CoderError
	];

	// ImageMagick error severities that mean "the engine cannot do this".
	private const CAPABILITY_ERRORS = [
		415 => true, // DelegateError
		420 => true, // MissingDelegateError
	];

	/** @var array<string, bool> Format listing cache keyed by ImageMagick format name. */
	private array $listed = [];

	private ?bool $colorManaged = null;

	// Whether this Imagick build's chromaticity setters take a z coordinate (ImageMagick 7).
	private static ?bool $chromaticityTakesZ = null;


	/** {@inheritDoc} */
	public static function isAvailable(): bool {
		return \extension_loaded('imagick') && \class_exists(\Imagick::class);
	}


	/** {@inheritDoc} */
	public function name(): string {
		return 'imagick';
	}


	/** {@inheritDoc} */
	public function version(): ?string {
		$version = \Imagick::getVersion()['versionString'] ?? null;

		return \is_string($version) ? $version : null;
	}


	/** {@inheritDoc} */
	public function canDecode(ImageFormat $format): bool {
		$magick = self::MAGICK[$format->value] ?? null;

		return $magick !== null && ($this->listed[$magick] ??= self::engine('list formats', static fn(): bool => \Imagick::queryFormats($magick) !== []));
	}


	/** {@inheritDoc} */
	public function canEncode(ImageFormat $format): bool {
		return $this->canDecode($format);
	}


	/** {@inheritDoc} */
	public function canDecodeFirstFrame(ImageFormat $format): bool {
		// Frame 0 is read directly; for APNG ImageMagick 6 returns the default image.
		return match ($format) {
			ImageFormat::Gif, ImageFormat::Png, ImageFormat::Webp, ImageFormat::Tiff => true,
			default => false,
		};
	}


	/** {@inheritDoc} */
	public function canManageColor(): bool {
		return $this->colorManaged ??= self::engine('list delegates', static function (): bool {
			$options = \Imagick::getConfigureOptions('DELEGATES');

			return \stripos((string)($options['DELEGATES'] ?? ''), 'lcms') !== false;
		});
	}


	/** {@inheritDoc} */
	public function appliesContainerTransforms(ImageFormat $format): bool {
		return $format === ImageFormat::Heic || $format === ImageFormat::Avif;
	}


	/** {@inheritDoc} */
	public function probeImage(): object {
		return self::engine('create the probe image', static function (): \Imagick {
			$probe = new \Imagick();
			$probe->newImage(self::PROBE_WIDTH, self::PROBE_HEIGHT, new \ImagickPixel('transparent'));
			$probe->setImageColorspace(\Imagick::COLORSPACE_SRGB);
			$draw = new \ImagickDraw();
			$draw->setFillColor(new \ImagickPixel('rgb(200, 40, 60)'));
			$draw->rectangle(0, 0, 15, self::PROBE_HEIGHT - 1);
			$draw->setFillColor(new \ImagickPixel('rgba(40, 170, 210, 0.4)'));
			$draw->rectangle(16, 0, 31, self::PROBE_HEIGHT - 1);
			$draw->setFillColor(new \ImagickPixel('rgba(40, 170, 210, 0.6)'));
			$draw->rectangle(32, 0, 47, self::PROBE_HEIGHT - 1);
			$probe->drawImage($draw);

			return $probe;
		});
	}


	/** {@inheritDoc} */
	public function pixelAt(object $image, int $x, int $y): array {
		$image = self::imagick($image);

		return self::engine('read a pixel', static function () use ($image, $x, $y): array {
			$color = $image->getImagePixelColor($x, $y)->getColor(1);

			return [
				(int)\round($color['r'] * 255),
				(int)\round($color['g'] * 255),
				(int)\round($color['b'] * 255),
				(int)\round(($color['a'] ?? 1.0) * 255),
			];
		});
	}


	/** {@inheritDoc} */
	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object {
		if (isset(self::MEMORY_READ[$format->value])) {
			$data = @\file_get_contents($path);

			if ($data === false) {
				throw new \RuntimeException('Failed to read image file: ' . $path);
			}

			return $this->decodeString($data, $format, $sourceProfile);
		}

		$handle = @\fopen($path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Failed to open image file: ' . $path);
		}

		try {
			return $this->read($format, static fn(\Imagick $image): bool => $image->readImageFile($handle), $sourceProfile);
		} finally {
			\fclose($handle);
		}
	}


	/** {@inheritDoc} */
	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object {
		return $this->read($format, static fn(\Imagick $image): bool => $image->readImageBlob($data), $sourceProfile);
	}


	/** {@inheritDoc} */
	public function size(object $image): array {
		$image = self::imagick($image);

		return self::engine('read the image size', static fn(): array => [$image->getImageWidth(), $image->getImageHeight()]);
	}


	/** {@inheritDoc} */
	public function render(object $image, array $plan): object {
		$image = self::imagick($image);
		[$x, $y, $width, $height, $targetWidth, $targetHeight] = $plan;

		return self::engine('render an image region', static function () use ($image, $x, $y, $width, $height, $targetWidth, $targetHeight): \Imagick {
			$sourceWidth = $image->getImageWidth();
			$sourceHeight = $image->getImageHeight();
			$full = $x === 0 && $y === 0 && $width === $sourceWidth && $height === $sourceHeight;

			if ($full && $width === $targetWidth && $height === $targetHeight) {
				return $image;
			}

			$target = clone $image;

			if (!$full) {
				$target->cropImage($width, $height, $x, $y);
				$target->setImagePage(0, 0, 0, 0);
			}

			if ($width !== $targetWidth || $height !== $targetHeight) {
				$target->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1.0);
				$target->setImagePage(0, 0, 0, 0);
			}

			return $target;
		});
	}


	/** {@inheritDoc} */
	public function orient(object $image, int $orientation, bool $owned): object {
		$image = self::imagick($image);

		if ($orientation === 1) {
			return $image;
		}

		return self::engine('orient the image', static function () use ($image, $orientation, $owned): \Imagick {
			$target = $owned ? $image : clone $image;
			$transparent = new \ImagickPixel('transparent');

			match ($orientation) {
				2 => $target->flopImage(),
				3 => $target->rotateImage($transparent, 180),
				4 => $target->flipImage(),
				5 => $target->transposeImage(),
				6 => $target->rotateImage($transparent, 90),
				7 => $target->transverseImage(),
				8 => $target->rotateImage($transparent, 270),
			};

			$target->setImagePage(0, 0, 0, 0);

			return $target;
		});
	}


	/** {@inheritDoc} */
	public function fade(object $image, int $opacity): object {
		$image = self::imagick($image);

		return self::engine('fade the overlay', static function () use ($image, $opacity): \Imagick {
			$faded = clone $image;

			if (!$faded->getImageAlphaChannel()) {
				$faded->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
			}

			$faded->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);

			return $faded;
		});
	}


	/** {@inheritDoc} */
	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object {
		$base = self::imagick($base);
		$overlay = self::imagick($overlay);

		return self::engine('composite the overlay', static function () use ($base, $overlay, $x, $y, $owned): \Imagick {
			$target = $owned ? $base : clone $base;
			$target->compositeImage($overlay, \Imagick::COMPOSITE_OVER, $x, $y);

			return $target;
		});
	}


	/** {@inheritDoc} */
	public function encode(object $image, ImageFormat $format, array $settings): string {
		$image = self::imagick($image);

		$data = self::engine('encode ' . $format->value, function () use ($image, $format, $settings): string {
			$prepared = $this->prepare($image, $format, $settings);

			try {
				return $prepared->getImageBlob();
			} finally {
				$prepared->clear();
			}
		});

		if ($data === '') {
			throw new \RuntimeException('ImageMagick produced no ' . $format->value . ' output.');
		}

		return $data;
	}


	/** {@inheritDoc} */
	public function write(object $image, ImageFormat $format, array $settings, string $path): void {
		$image = self::imagick($image);
		$handle = @\fopen($path, 'wb');

		if ($handle === false) {
			throw new \RuntimeException('Failed to open image file for writing: ' . $path);
		}

		try {
			self::engine('encode ' . $format->value . ' to ' . $path, function () use ($image, $format, $settings, $handle): void {
				$prepared = $this->prepare($image, $format, $settings);

				try {
					$prepared->writeImageFile($handle);
				} finally {
					$prepared->clear();
				}
			});
		} finally {
			\fclose($handle);
		}
	}


	/** {@inheritDoc} */
	public function release(object $image): void {
		if (!$image instanceof \Imagick) {
			return;
		}

		try {
			$image->clear();
		} catch (\ImagickException) {
			// Best-effort cleanup: never mask the operation's primary failure.
		}
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Read frame 0 with a forced decoder and normalize the result.
	 *
	 * @param ImageFormat $format Format detected by the Image service.
	 * @param \Closure(\Imagick): bool $reader Performs the actual read.
	 * @param string|null $sourceProfile ICC profile to convert from, or null.
	 * @return \Imagick Normalized image.
	 * @throws ImageInputException When ImageMagick reports undecodable data, or the profile does not match or cannot be applied.
	 * @throws ImageCapabilityException When a required delegate is missing.
	 * @throws \RuntimeException On resource or other engine failures.
	 */
	private function read(ImageFormat $format, \Closure $reader, ?string $sourceProfile): \Imagick {
		// Prefix forces the coder; the subscript limits decoding to frame 0.
		$image = self::engine('prepare the decoder', static function () use ($format): \Imagick {
			$image = new \Imagick();
			$image->setFilename(self::MAGICK[$format->value] . ':image[0]');

			return $image;
		});

		try {
			$reader($image);
		} catch (\ImagickException $e) {
			$code = $e->getCode();

			if (isset(self::INPUT_ERRORS[$code])) {
				throw new ImageInputException('Image data could not be decoded as ' . $format->value . '.', 0, $e);
			}

			if (isset(self::CAPABILITY_ERRORS[$code])) {
				throw new ImageCapabilityException('ImageMagick lacks a working ' . $format->value . ' delegate.', 0, $e);
			}

			throw new \RuntimeException('ImageMagick failed to read ' . $format->value . ': ' . $e->getMessage(), 0, $e);
		}

		if ($sourceProfile !== null) {
			$this->convertToSrgb($image, $sourceProfile);
		}

		return self::engine('normalize the decoded image', fn(): \Imagick => $this->normalize($image));
	}


	/**
	 * Set one chromaticity coordinate on either Imagick build.
	 *
	 * Behavior:
	 * - Imagick built against ImageMagick 7 takes (x, y, z); against
	 *   ImageMagick 6 it takes (x, y). The compiled signature is read once by
	 *   reflection rather than inferred from a version number. z is 1 - x - y,
	 *   ImageMagick 7's own convention.
	 *
	 * @param \Imagick $image Image to modify.
	 * @param string $method setImageRedPrimary, setImageGreenPrimary, setImageBluePrimary, or setImageWhitePoint.
	 * @param float $x CIE x.
	 * @param float $y CIE y.
	 * @return void
	 */
	private static function chromaticity(\Imagick $image, string $method, float $x, float $y): void {
		self::$chromaticityTakesZ ??= (new \ReflectionMethod(\Imagick::class, 'setImageRedPrimary'))->getNumberOfRequiredParameters() >= 3;

		if (self::$chromaticityTakesZ) {
			$image->{$method}($x, $y, 1.0 - $x - $y);
		} else {
			$image->{$method}($x, $y);
		}
	}


	/**
	 * Describe HEIC/AVIF output as sRGB (CICP 1/13/6, full range).
	 *
	 * Behavior:
	 * - ImageMagick 7 otherwise preserves the source's CICP, which after a
	 *   conversion would label sRGB pixels as, for example, Display P3.
	 * - Builds that do not know these defines ignore them; the Image service
	 *   verifies every output's color description either way.
	 *
	 * @param \Imagick $image Output image; options are set on it.
	 * @return void
	 */
	private static function srgbCicp(\Imagick $image): void {
		$image->setOption('heic:preserve-cicp', 'false');
		$image->setOption('heic:cicp', '1/13/6/1');
	}


	/**
	 * Convert decoded pixels from a source ICC profile to sRGB.
	 *
	 * Behavior:
	 * - An RGB profile on data ImageMagick decoded as YCbCr (the ImageMagick
	 *   6.9.11 HEIF coder) first gets the plain YCbCr-to-RGB matrix step; the
	 *   values then still belong to the source space.
	 * - The profile's color model must match the data (RGB, gray, or CMYK).
	 * - Perceptual intent: uses a profile's perceptual tables where present
	 *   (print profiles) and equals relative colorimetric for matrix profiles.
	 *
	 * @param \Imagick $image Freshly decoded image; mutated.
	 * @param string $profile Source ICC profile.
	 * @return void
	 * @throws ImageInputException When the profile does not match the data or cannot be applied.
	 * @throws \RuntimeException On other engine failures.
	 */
	private function convertToSrgb(\Imagick $image, string $profile): void {
		$space = IccProfile::dataSpace($profile);

		$matches = self::engine('prepare color conversion', static function () use ($image, $space): bool {
			$colorspace = $image->getImageColorspace();

			if ($space === 'rgb' && !\in_array($colorspace, [\Imagick::COLORSPACE_SRGB, \Imagick::COLORSPACE_RGB, \Imagick::COLORSPACE_GRAY, \Imagick::COLORSPACE_CMYK], true)) {
				$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
				$colorspace = $image->getImageColorspace();
			}

			return match ($space) {
				'rgb' => $colorspace === \Imagick::COLORSPACE_SRGB || $colorspace === \Imagick::COLORSPACE_RGB,
				'gray' => $colorspace === \Imagick::COLORSPACE_GRAY,
				'cmyk' => $colorspace === \Imagick::COLORSPACE_CMYK,
				default => false,
			};
		});

		if (!$matches) {
			throw new ImageInputException('The embedded ' . ($space ?? 'unknown') . ' ICC profile does not match the image data.');
		}

		try {
			$image->setImageProfile('icc', $profile);
			$image->setImageRenderingIntent(\Imagick::RENDERINGINTENT_PERCEPTUAL);
			$image->profileImage('icc', Profiles::srgb());

			if (isset($image->getImageProfiles('icc', false)[0])) {
				$image->removeImageProfile('icc');
			}
		} catch (\ImagickException $e) {
			throw new ImageInputException('The embedded ICC profile could not be applied.', 0, $e);
		}
	}


	/**
	 * Normalize a freshly decoded image.
	 *
	 * Must be called inside engine().
	 *
	 * @param \Imagick $image Decoded image; owned.
	 * @return \Imagick Single-frame sRGB image without page offset.
	 */
	private function normalize(\Imagick $image): \Imagick {
		// The forcing filename ("FMT:image[0]") would otherwise stay on the wand
		// and override the output format chosen at encode time.
		$image->setFilename('');
		$image->setImageFilename('');
		$image->setIteratorIndex(0);
		$image->setImagePage(0, 0, 0, 0);

		if ($image->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
			$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		}

		$image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

		return $image;
	}


	/**
	 * Build an encode-ready copy carrying the package's output semantics.
	 *
	 * Must be called inside engine().
	 *
	 * @param \Imagick $image Image; not modified.
	 * @param ImageFormat $format Output format.
	 * @param array<string, mixed> $settings Resolved encoder settings.
	 * @return \Imagick Prepared copy; caller clears it.
	 */
	private function prepare(\Imagick $image, ImageFormat $format, array $settings): \Imagick {
		$out = clone $image;

		if (!$format->keepsAlpha() && $out->getImageAlphaChannel()) {
			[$red, $green, $blue] = $settings['background'];
			$out->setImageBackgroundColor(new \ImagickPixel(\sprintf('rgb(%d, %d, %d)', $red, $green, $blue)));
			$out->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
		}

		$out->stripImage();
		$out->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
		$out->setImageDepth(8);
		$out->setImagePage(0, 0, 0, 0);

		// The pixels are sRGB. Color fields a decoder read from the source
		// (PNG cHRM/gAMA) would otherwise reach formats that write them (TIFF
		// WhitePoint/PrimaryChromaticities), labeling sRGB pixels as another
		// space. Reset them to sRGB (BT.709 primaries, D65).
		self::chromaticity($out, 'setImageRedPrimary', 0.64, 0.33);
		self::chromaticity($out, 'setImageGreenPrimary', 0.30, 0.60);
		self::chromaticity($out, 'setImageBluePrimary', 0.15, 0.06);
		self::chromaticity($out, 'setImageWhitePoint', 0.3127, 0.3290);
		$out->setImageGamma(1 / 2.2);

		switch ($format) {
			case ImageFormat::Jpeg:
				$out->setImageFormat('JPEG');
				$out->setImageCompressionQuality((int)$settings['quality']);
				$out->setInterlaceScheme($settings['progressive'] ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
				break;

			case ImageFormat::Png:
				$out->setImageFormat($out->getImageAlphaChannel() ? 'PNG32' : 'PNG24');
				// No ancillary chunks at all. stripImage() leaves gAMA and cHRM to
				// the writer's judgment; this keeps PNG output free of any color
				// description on every ImageMagick version. Alpha needs no tRNS
				// because PNG32 carries an alpha channel.
				$out->setOption('png:exclude-chunk', 'all');
				// Tens digit: zlib level; ones digit 5: adaptive filtering.
				$out->setImageCompressionQuality((int)$settings['compression'] * 10 + 5);
				break;

			case ImageFormat::Webp:
				$out->setImageFormat('WEBP');
				$out->setImageCompressionQuality((int)$settings['quality']);
				break;

			case ImageFormat::Avif:
				$out->setImageFormat('AVIF');
				$out->setImageCompressionQuality((int)$settings['quality']);
				$out->setOption('heic:speed', (string)$settings['speed']);
				self::srgbCicp($out);
				break;

			case ImageFormat::Heic:
				$out->setImageFormat('HEIC');
				$out->setImageCompressionQuality((int)($settings['quality'] ?? 80));
				self::srgbCicp($out);
				break;

			case ImageFormat::Bmp:
				$out->setImageFormat('BMP3');
				$out->setImageType(\Imagick::IMGTYPE_TRUECOLOR);
				break;

			case ImageFormat::Gif:
				// Package rule, enforced here rather than left to the GIF writer:
				// pixels less than 50% opaque become fully transparent, all others
				// fully opaque. The writer then reduces to 256 colors.
				if ($out->getImageAlphaChannel()) {
					$out->thresholdImage(0.5 * \Imagick::getQuantumRange()['quantumRangeLong'], \Imagick::CHANNEL_ALPHA);
				}

				$out->setImageFormat('GIF');
				break;

			case ImageFormat::Tiff:
				$out->setImageFormat('TIFF');
				$out->setImageCompression(\Imagick::COMPRESSION_LZW);
				break;
		}

		return $out;
	}


	/**
	 * Run engine calls and keep native Imagick exceptions behind the seam.
	 *
	 * @template T
	 * @param string $operation What was attempted, for the message.
	 * @param \Closure(): T $call Engine calls.
	 * @return T Result of $call.
	 * @throws \RuntimeException When ImageMagick throws.
	 */
	private static function engine(string $operation, \Closure $call): mixed {
		try {
			return $call();
		} catch (\ImagickException | \ImagickPixelException | \ImagickDrawException | \ImagickPixelIteratorException | \ImagickKernelException $e) {
			throw new \RuntimeException('ImageMagick failed to ' . $operation . ': ' . $e->getMessage(), 0, $e);
		}
	}


	/**
	 * Narrow an opaque handle to \Imagick.
	 *
	 * @param object $image Handle.
	 * @return \Imagick The handle.
	 * @throws \LogicException When the handle belongs to another backend.
	 */
	private static function imagick(object $image): \Imagick {
		if (!$image instanceof \Imagick) {
			throw new \LogicException('Imagick backend received a ' . $image::class . ' handle.');
		}

		return $image;
	}


}

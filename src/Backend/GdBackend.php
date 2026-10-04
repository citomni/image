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

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageInputException;

/**
 * GD implementation of the internal image backend.
 *
 * @internal Used by the Image service only.
 *
 * Behavior:
 * - Decodes files through GD's per-format file readers, so the source is
 *   never held as a PHP string.
 * - Normalizes every decoded image to truecolor with alpha saving enabled and
 *   no transparent color key. GD's copy primitives skip keyed pixels, so a key
 *   left in place would make crops and flattening depend on hidden state.
 * - Orientation uses GD's lossless quarter-turn paths and in-place flips on
 *   owned images only.
 * - Formats without output alpha (JPEG, BMP) are composited onto the resolved
 *   background before encoding.
 *
 * Notes:
 * - GIF output is not offered: GD reduces to a palette without a defined
 *   transparency rule, which would make alpha semantics backend-dependent.
 * - GD discards ICC profiles and has no color management.
 * - Only the first frame of GIF input is decoded; for APNG, libpng returns the
 *   default image.
 * - imagedestroy() is not used; handles are freed when released references
 *   go out of scope.
 */
final class GdBackend implements ImageBackend {

	private const TYPE_FLAGS = [
		'jpeg' => \IMG_JPG,
		'png' => \IMG_PNG,
		'gif' => \IMG_GIF,
		'webp' => \IMG_WEBP,
		'avif' => \IMG_AVIF,
		'bmp' => \IMG_BMP,
	];

	private int $types;


	/**
	 * Cache the GD build's supported type bitmask.
	 */
	public function __construct() {
		$this->types = \imagetypes();
	}


	/** {@inheritDoc} */
	public static function isAvailable(): bool {
		return \extension_loaded('gd');
	}


	/** {@inheritDoc} */
	public function name(): string {
		return 'gd';
	}


	/** {@inheritDoc} */
	public function version(): ?string {
		$version = \gd_info()['GD Version'] ?? null;

		return \is_string($version) ? $version : null;
	}


	/** {@inheritDoc} */
	public function canDecode(ImageFormat $format): bool {
		$flag = self::TYPE_FLAGS[$format->value] ?? 0;

		if ($flag === 0 || ($this->types & $flag) === 0) {
			return false;
		}

		return $format !== ImageFormat::Avif || \function_exists('imagecreatefromavif');
	}


	/** {@inheritDoc} */
	public function canEncode(ImageFormat $format): bool {
		return match ($format) {
			ImageFormat::Jpeg, ImageFormat::Png, ImageFormat::Webp, ImageFormat::Bmp => $this->canDecode($format),
			ImageFormat::Avif => $this->canDecode($format) && \function_exists('imageavif'),
			default => false,
		};
	}


	/** {@inheritDoc} */
	public function canDecodeFirstFrame(ImageFormat $format): bool {
		return $format === ImageFormat::Gif || $format === ImageFormat::Png;
	}


	/** {@inheritDoc} */
	public function canManageColor(): bool {
		// GD has no color engine.
		return false;
	}


	/** {@inheritDoc} */
	public function appliesContainerTransforms(ImageFormat $format): bool {
		// libgd's AVIF reader returns the coded frame; transforms are left to the caller.
		return false;
	}


	/** {@inheritDoc} */
	public function probeImage(): object {
		$probe = $this->allocate(self::PROBE_WIDTH, self::PROBE_HEIGHT);
		// GD alpha: 0 opaque, 127 transparent; 76 = 40% and 51 = 60% opacity.
		$zones = [
			[0, 15, \imagecolorallocatealpha($probe, 200, 40, 60, 0)],
			[16, 31, \imagecolorallocatealpha($probe, 40, 170, 210, 76)],
			[32, 47, \imagecolorallocatealpha($probe, 40, 170, 210, 51)],
			[48, self::PROBE_WIDTH - 1, \imagecolorallocatealpha($probe, 0, 0, 0, 127)],
		];

		foreach ($zones as [$from, $to, $color]) {
			if ($color === false || !\imagefilledrectangle($probe, $from, 0, $to, self::PROBE_HEIGHT - 1, $color)) {
				throw new \RuntimeException('GD failed to draw the probe image.');
			}
		}

		return $probe;
	}


	/** {@inheritDoc} */
	public function pixelAt(object $image, int $x, int $y): array {
		$color = \imagecolorat(self::gd($image), $x, $y);

		// GD alpha: 0 opaque, 127 transparent.
		return [
			($color >> 16) & 0xFF,
			($color >> 8) & 0xFF,
			$color & 0xFF,
			\intdiv((127 - (($color >> 24) & 0x7F)) * 510 + 127, 254),
		];
	}


	/** {@inheritDoc} */
	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object {
		self::assertNoColorConversion($sourceProfile);

		$image = match ($format) {
			ImageFormat::Jpeg => @\imagecreatefromjpeg($path),
			ImageFormat::Png => @\imagecreatefrompng($path),
			ImageFormat::Gif => @\imagecreatefromgif($path),
			ImageFormat::Webp => @\imagecreatefromwebp($path),
			ImageFormat::Avif => \function_exists('imagecreatefromavif') ? @\imagecreatefromavif($path) : false,
			ImageFormat::Bmp => @\imagecreatefrombmp($path),
			default => false,
		};

		if ($image === false) {
			throw new ImageInputException('Image data could not be decoded as ' . $format->value . '.');
		}

		return $this->normalize($image);
	}


	/** {@inheritDoc} */
	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object {
		self::assertNoColorConversion($sourceProfile);

		$image = @\imagecreatefromstring($data);

		if ($image === false) {
			throw new ImageInputException('Image data could not be decoded as ' . $format->value . '.');
		}

		return $this->normalize($image);
	}


	/** {@inheritDoc} */
	public function size(object $image): array {
		$image = self::gd($image);

		return [\imagesx($image), \imagesy($image)];
	}


	/** {@inheritDoc} */
	public function render(object $image, array $plan): object {
		$image = self::gd($image);
		[$x, $y, $width, $height, $targetWidth, $targetHeight] = $plan;

		if (
			$x === 0
			&& $y === 0
			&& $width === $targetWidth
			&& $height === $targetHeight
			&& $width === \imagesx($image)
			&& $height === \imagesy($image)
		) {
			return $image;
		}

		$target = $this->allocate($targetWidth, $targetHeight);

		$ok = ($width === $targetWidth && $height === $targetHeight)
			? \imagecopy($target, $image, 0, 0, $x, $y, $width, $height)
			: \imagecopyresampled($target, $image, 0, 0, $x, $y, $targetWidth, $targetHeight, $width, $height);

		if (!$ok) {
			throw new \RuntimeException('GD failed to render image region.');
		}

		return $target;
	}


	/** {@inheritDoc} */
	public function orient(object $image, int $orientation, bool $owned): object {
		$image = self::gd($image);

		// GD rotates counter-clockwise; imageflip() mutates in place.
		return match ($orientation) {
			2 => $this->flip($owned ? $image : $this->duplicate($image), \IMG_FLIP_HORIZONTAL),
			3 => $this->rotateCounterClockwise($image, 180),
			4 => $this->flip($owned ? $image : $this->duplicate($image), \IMG_FLIP_VERTICAL),
			5 => $this->flip($this->rotateCounterClockwise($image, 90), \IMG_FLIP_VERTICAL),
			6 => $this->rotateCounterClockwise($image, 270),
			7 => $this->flip($this->rotateCounterClockwise($image, 270), \IMG_FLIP_VERTICAL),
			8 => $this->rotateCounterClockwise($image, 90),
			default => $image,
		};
	}


	/** {@inheritDoc} */
	public function fade(object $image, int $opacity): object {
		$copy = $this->duplicate(self::gd($image));
		$width = \imagesx($copy);
		$height = \imagesy($copy);

		// GD has no alpha-multiply primitive, so this walks the pixels in PHP
		// (roughly 50-100 ms per megapixel). The Image service fades each
		// overlay once per job and size; fully transparent pixels are skipped.
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = \imagecolorat($copy, $x, $y);
				$alpha = ($color >> 24) & 0x7F;

				if ($alpha === 127) {
					continue;
				}

				// GD alpha: 0 opaque, 127 transparent. Scale opacity, round half up.
				$faded = 127 - \intdiv((127 - $alpha) * $opacity * 2 + 100, 200);
				\imagesetpixel($copy, $x, $y, ($color & 0x00FFFFFF) | ($faded << 24));
			}
		}

		return $copy;
	}


	/** {@inheritDoc} */
	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object {
		$base = self::gd($base);
		$overlay = self::gd($overlay);
		$target = $owned ? $base : $this->duplicate($base);

		\imagealphablending($target, true);
		$ok = \imagecopy($target, $overlay, $x, $y, 0, 0, \imagesx($overlay), \imagesy($overlay));
		\imagealphablending($target, false);

		if (!$ok) {
			throw new \RuntimeException('GD failed to composite the overlay.');
		}

		return $target;
	}


	/** {@inheritDoc} */
	public function encode(object $image, ImageFormat $format, array $settings): string {
		\ob_start();

		try {
			$this->writeTo(self::gd($image), $format, $settings, null);
			$data = (string)\ob_get_contents();
		} finally {
			\ob_end_clean();
		}

		if ($data === '') {
			throw new \RuntimeException('GD produced no ' . $format->value . ' output.');
		}

		return $data;
	}


	/** {@inheritDoc} */
	public function write(object $image, ImageFormat $format, array $settings, string $path): void {
		$this->writeTo(self::gd($image), $format, $settings, $path);
	}


	/** {@inheritDoc} */
	public function release(object $image): void {
		// GD handles are freed when the last reference is dropped; nothing to do.
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Run the GD encoder for a supported output format.
	 *
	 * @param \GdImage $image Image; not modified.
	 * @param ImageFormat $format Output format.
	 * @param array<string, mixed> $settings Resolved encoder settings.
	 * @param string|null $path Target file, or null for the output buffer.
	 * @return void
	 * @throws \RuntimeException When encoding fails.
	 */
	private function writeTo(\GdImage $image, ImageFormat $format, array $settings, ?string $path): void {
		$ok = match ($format) {
			ImageFormat::Jpeg => $this->writeJpeg($image, $settings, $path),
			ImageFormat::Png => @\imagepng($image, $path, (int)$settings['compression']),
			ImageFormat::Webp => @\imagewebp($image, $path, (int)$settings['quality']),
			ImageFormat::Avif => @\imageavif($image, $path, (int)$settings['quality'], (int)$settings['speed']),
			ImageFormat::Bmp => @\imagebmp($this->flatten($image, $settings['background']), $path, false),
			default => throw new \LogicException('GD backend cannot encode ' . $format->value . '.'),
		};

		if (!$ok) {
			throw new \RuntimeException('GD failed to encode ' . $format->value . ($path !== null ? ': ' . $path : '.'));
		}
	}


	/**
	 * Flatten and encode JPEG.
	 *
	 * @param \GdImage $image Image; not modified.
	 * @param array<string, mixed> $settings Resolved encoder settings.
	 * @param string|null $path Target file, or null for the output buffer.
	 * @return bool True on success.
	 */
	private function writeJpeg(\GdImage $image, array $settings, ?string $path): bool {
		$flat = $this->flatten($image, $settings['background']);
		\imageinterlace($flat, (bool)$settings['progressive']);

		return @\imagejpeg($flat, $path, (int)$settings['quality']);
	}


	/**
	 * Normalize a freshly decoded image.
	 *
	 * @param \GdImage $image Decoded image; owned.
	 * @return \GdImage Truecolor image with alpha only and no color key.
	 * @throws \RuntimeException When GD fails.
	 */
	private function normalize(\GdImage $image): \GdImage {
		if (!\imageistruecolor($image) && !\imagepalettetotruecolor($image)) {
			throw new \RuntimeException('GD failed to convert a palette image to truecolor.');
		}

		if (\imagecolortransparent($image) !== -1) {
			// Copy onto a transparent buffer; GD skips keyed pixels, which
			// therefore stay transparent, and copies all others verbatim.
			$width = \imagesx($image);
			$height = \imagesy($image);
			$target = $this->allocate($width, $height);
			$clear = \imagecolorallocatealpha($target, 0, 0, 0, 127);

			if (
				$clear === false
				|| !\imagefilledrectangle($target, 0, 0, $width - 1, $height - 1, $clear)
				|| !\imagecopy($target, $image, 0, 0, 0, 0, $width, $height)
			) {
				throw new \RuntimeException('GD failed to resolve a transparent color key.');
			}

			return $target;
		}

		\imagealphablending($image, false);
		\imagesavealpha($image, true);

		return $image;
	}


	/**
	 * Composite onto an opaque background.
	 *
	 * @param \GdImage $image Image; not modified.
	 * @param mixed $background Resolved background array{0:int, 1:int, 2:int}.
	 * @return \GdImage New opaque image.
	 * @throws \RuntimeException When GD fails.
	 */
	private function flatten(\GdImage $image, mixed $background): \GdImage {
		[$red, $green, $blue] = $background;
		$width = \imagesx($image);
		$height = \imagesy($image);
		$flat = $this->allocate($width, $height);
		$color = \imagecolorallocate($flat, $red, $green, $blue);

		if ($color === false || !\imagefilledrectangle($flat, 0, 0, $width - 1, $height - 1, $color)) {
			throw new \RuntimeException('GD failed to prepare the background.');
		}

		\imagealphablending($flat, true);

		if (!\imagecopy($flat, $image, 0, 0, 0, 0, $width, $height)) {
			throw new \RuntimeException('GD failed to flatten the image.');
		}

		return $flat;
	}


	/**
	 * Rotate by an exact quarter turn (lossless fast path).
	 *
	 * @param \GdImage $image Image; not modified.
	 * @param int $degrees Counter-clockwise degrees: 90, 180, or 270.
	 * @return \GdImage New rotated image.
	 * @throws \RuntimeException When GD fails.
	 */
	private function rotateCounterClockwise(\GdImage $image, int $degrees): \GdImage {
		$rotated = \imagerotate($image, $degrees, 0);

		if ($rotated === false) {
			throw new \RuntimeException('GD failed to rotate the image.');
		}

		\imagealphablending($rotated, false);
		\imagesavealpha($rotated, true);

		return $rotated;
	}


	/**
	 * Flip an owned image in place.
	 *
	 * @param \GdImage $image Image to mutate.
	 * @param int $mode IMG_FLIP_HORIZONTAL or IMG_FLIP_VERTICAL.
	 * @return \GdImage The same instance.
	 * @throws \RuntimeException When GD fails.
	 */
	private function flip(\GdImage $image, int $mode): \GdImage {
		if (!\imageflip($image, $mode)) {
			throw new \RuntimeException('GD failed to flip the image.');
		}

		return $image;
	}


	/**
	 * Copy an image into a new buffer.
	 *
	 * @param \GdImage $image Image; not modified.
	 * @return \GdImage New identical image.
	 * @throws \RuntimeException When GD fails.
	 */
	private function duplicate(\GdImage $image): \GdImage {
		$width = \imagesx($image);
		$height = \imagesy($image);
		$copy = $this->allocate($width, $height);

		if (!\imagecopy($copy, $image, 0, 0, 0, 0, $width, $height)) {
			throw new \RuntimeException('GD failed to copy the image.');
		}

		return $copy;
	}


	/**
	 * Allocate a truecolor buffer prepared for alpha-preserving writes.
	 *
	 * @param int $width Width, >= 1.
	 * @param int $height Height, >= 1.
	 * @return \GdImage New image.
	 * @throws \RuntimeException When GD cannot allocate the buffer.
	 */
	private function allocate(int $width, int $height): \GdImage {
		$image = @\imagecreatetruecolor($width, $height);

		if ($image === false) {
			throw new \RuntimeException(\sprintf('GD failed to allocate a %dx%d image.', $width, $height));
		}

		\imagealphablending($image, false);
		\imagesavealpha($image, true);

		return $image;
	}


	/**
	 * Fail fast when asked for a color conversion GD cannot perform.
	 *
	 * @param string|null $sourceProfile Requested source profile.
	 * @return void
	 * @throws \LogicException When a conversion is requested (canManageColor() is false).
	 */
	private static function assertNoColorConversion(?string $sourceProfile): void {
		if ($sourceProfile !== null) {
			throw new \LogicException('GD backend cannot convert color profiles.');
		}
	}


	/**
	 * Narrow an opaque handle to \GdImage.
	 *
	 * @param object $image Handle.
	 * @return \GdImage The handle.
	 * @throws \LogicException When the handle belongs to another backend.
	 */
	private static function gd(object $image): \GdImage {
		if (!$image instanceof \GdImage) {
			throw new \LogicException('GD backend received a ' . $image::class . ' handle.');
		}

		return $image;
	}


}

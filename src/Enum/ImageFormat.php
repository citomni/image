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

namespace CitOmni\Image\Enum;

/**
 * Bounded vocabulary of image formats known to citomni/image.
 *
 * Knowing a format is not the same as supporting it. Whether a format can be
 * decoded or encoded is a runtime capability reported by the Image service.
 *
 * Behavior:
 * - The backing value is the public wire form used in output specifications,
 *   inspect() results, and capability reports.
 * - Format traits (MIME type, alpha semantics, dimension limits, setting
 *   vocabulary) are defined here once and shared by all backends.
 *
 * Notes:
 * - Output alpha semantics are package-level guarantees, not backend
 *   properties: BMP output is always flattened, even where a backend could
 *   write 32-bit BMP, so that results do not depend on the backend.
 *
 * Typical usage:
 *   $format = ImageFormat::tryFrom('webp') ?? throw new \InvalidArgumentException('...');
 */
enum ImageFormat: string {
	case Jpeg = 'jpeg';
	case Png = 'png';
	case Gif = 'gif';
	case Webp = 'webp';
	case Avif = 'avif';
	case Bmp = 'bmp';
	case Tiff = 'tiff';
	case Heic = 'heic';


	/**
	 * Map a PHP IMAGETYPE_* constant to a known format.
	 *
	 * @param int $type Value from getimagesize() index 2.
	 * @return self|null Format, or null for types outside the vocabulary.
	 */
	public static function fromImageType(int $type): ?self {
		return match (true) {
			$type === \IMAGETYPE_JPEG => self::Jpeg,
			$type === \IMAGETYPE_PNG => self::Png,
			$type === \IMAGETYPE_GIF => self::Gif,
			$type === \IMAGETYPE_WEBP => self::Webp,
			$type === \IMAGETYPE_AVIF => self::Avif,
			$type === \IMAGETYPE_BMP => self::Bmp,
			$type === \IMAGETYPE_TIFF_II, $type === \IMAGETYPE_TIFF_MM => self::Tiff,
			\defined('IMAGETYPE_HEIF') && $type === \constant('IMAGETYPE_HEIF') => self::Heic,
			default => null,
		};
	}


	/**
	 * Return the canonical MIME type.
	 *
	 * @return string MIME type.
	 */
	public function mime(): string {
		return match ($this) {
			self::Jpeg => 'image/jpeg',
			self::Png => 'image/png',
			self::Gif => 'image/gif',
			self::Webp => 'image/webp',
			self::Avif => 'image/avif',
			self::Bmp => 'image/bmp',
			self::Tiff => 'image/tiff',
			self::Heic => 'image/heic',
		};
	}


	/**
	 * Whether output in this format preserves alpha.
	 *
	 * Formats returning false are flattened onto a background on output.
	 *
	 * @return bool True when output keeps alpha.
	 */
	public function keepsAlpha(): bool {
		return match ($this) {
			self::Jpeg, self::Bmp => false,
			default => true,
		};
	}


	/**
	 * Whether the format accepts the lossy "quality" setting (0-100).
	 *
	 * @return bool True for lossy formats.
	 */
	public function usesQuality(): bool {
		return match ($this) {
			self::Jpeg, self::Webp, self::Avif, self::Heic => true,
			default => false,
		};
	}


	/**
	 * Whether the format accepts the lossless "compression" setting (0-9).
	 *
	 * @return bool True for PNG.
	 */
	public function usesCompression(): bool {
		return $this === self::Png;
	}


	/**
	 * Hard per-axis dimension limit imposed by the format itself.
	 *
	 * @return int|null Maximum width/height in pixels, or null when only the
	 *                  pixel budget applies.
	 */
	public function maxDimension(): ?int {
		return match ($this) {
			self::Jpeg => 65_535,
			self::Webp => 16_383,
			default => null,
		};
	}


}

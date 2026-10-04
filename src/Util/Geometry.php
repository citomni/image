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

namespace CitOmni\Image\Util;

use CitOmni\Image\Enum\ImageFit;

/**
 * Geometry: Deterministic integer geometry for image rendering plans.
 *
 * A plan is a list of six ints: [x, y, width, height, targetWidth,
 * targetHeight]. The first four select a source rectangle; the last two give
 * the size it is resampled to.
 *
 * Behavior:
 * - All arithmetic is integer-only; rounding is half up.
 * - Orientation values are EXIF orientations 1-8. Orientations 5-8 swap axes.
 * - Plans are computed in display space (after orientation) and mapped back
 *   to stored space, so a renderer can resample the stored image first and
 *   orient only the small result.
 *
 * Notes:
 * - Pure functions: no App, no IO, no state.
 * - Inputs are assumed validated by the caller (positive dimensions, region
 *   inside bounds, orientation 1-8, products within 64-bit range).
 */
final class Geometry {

	// Orientation composition: applying orientation A, then orientation B,
	// equals the single orientation THEN[A][B - 1].
	private const THEN = [
		1 => [1, 2, 3, 4, 5, 6, 7, 8],
		2 => [2, 1, 4, 3, 8, 7, 6, 5],
		3 => [3, 4, 1, 2, 7, 8, 5, 6],
		4 => [4, 3, 2, 1, 6, 5, 8, 7],
		5 => [5, 6, 7, 8, 1, 2, 3, 4],
		6 => [6, 5, 8, 7, 4, 3, 2, 1],
		7 => [7, 8, 5, 6, 3, 4, 1, 2],
		8 => [8, 7, 6, 5, 2, 1, 4, 3],
	];

	// Orientation equivalent to 0, 1, 2, or 3 clockwise quarter turns.
	private const QUARTER_TURNS = [1, 6, 3, 8];


	/**
	 * Return the display size of a stored image under an orientation.
	 *
	 * @param int $width Stored width.
	 * @param int $height Stored height.
	 * @param int $orientation EXIF orientation 1-8.
	 * @return array{0:int, 1:int} Display width and height.
	 */
	public static function displaySize(int $width, int $height, int $orientation): array {
		return $orientation >= 5 ? [$height, $width] : [$width, $height];
	}


	/**
	 * Compose an EXIF orientation with an additional clockwise rotation.
	 *
	 * @param int $orientation EXIF orientation 1-8 applied first.
	 * @param int $degrees Clockwise rotation applied afterwards; a multiple of 90.
	 * @return int Equivalent single EXIF orientation 1-8.
	 */
	public static function compose(int $orientation, int $degrees): int {
		$quarterTurns = \intdiv((($degrees % 360) + 360) % 360, 90);

		return self::then($orientation, self::QUARTER_TURNS[$quarterTurns]);
	}


	/**
	 * Compose two orientations.
	 *
	 * @param int $first Orientation 1-8 applied first.
	 * @param int $second Orientation 1-8 applied afterwards.
	 * @return int Equivalent single orientation 1-8.
	 */
	public static function then(int $first, int $second): int {
		return self::THEN[$first][$second - 1];
	}


	/**
	 * Plan how a region is fitted into a target box.
	 *
	 * Behavior:
	 * - Contain: aspect-preserving scale to fit inside the box; a null
	 *   dimension is unconstrained; both null keeps the region size.
	 * - Cover: aspect-preserving scale to fill the box, centered trim.
	 * - Fill: independent per-axis scale to the box.
	 * - Without $upscale no axis is enlarged:
	 *   1) contain keeps the region size when it already fits
	 *   2) cover keeps the largest centered sub-region with the target aspect
	 *      ratio at source resolution
	 *   3) fill clamps each axis to the region size
	 *
	 * @param int $regionWidth Width of the region being fitted.
	 * @param int $regionHeight Height of the region being fitted.
	 * @param int|null $width Box width, or null (contain only).
	 * @param int|null $height Box height, or null (contain only).
	 * @param ImageFit $fit Fit mode.
	 * @param bool $upscale Allow enlarging.
	 * @return array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int} Plan relative to the region.
	 */
	public static function fit(int $regionWidth, int $regionHeight, ?int $width, ?int $height, ImageFit $fit, bool $upscale): array {
		$identity = [0, 0, $regionWidth, $regionHeight, $regionWidth, $regionHeight];

		if ($fit === ImageFit::Contain) {
			if ($width === null && $height === null) {
				return $identity;
			}

			if ($height === null || ($width !== null && $regionWidth * $height >= $regionHeight * $width)) {
				$targetWidth = $width;
				$targetHeight = \max(1, self::roundDiv($regionHeight * $width, $regionWidth));
			} else {
				$targetWidth = \max(1, self::roundDiv($regionWidth * $height, $regionHeight));
				$targetHeight = $height;
			}

			if (!$upscale && ($targetWidth > $regionWidth || $targetHeight > $regionHeight)) {
				return $identity;
			}

			return [0, 0, $regionWidth, $regionHeight, $targetWidth, $targetHeight];
		}

		if ($fit === ImageFit::Fill) {
			return [
				0,
				0,
				$regionWidth,
				$regionHeight,
				$upscale ? $width : \min($width, $regionWidth),
				$upscale ? $height : \min($height, $regionHeight),
			];
		}

		// Cover.
		if ($regionWidth * $height > $regionHeight * $width) {
			// Region is wider than the box ratio: trim left and right.
			$cropWidth = \max(1, \min($regionWidth, self::roundDiv($regionHeight * $width, $height)));
			$cropHeight = $regionHeight;
		} else {
			// Region is taller than (or equal to) the box ratio: trim top and bottom.
			$cropWidth = $regionWidth;
			$cropHeight = \max(1, \min($regionHeight, self::roundDiv($regionWidth * $height, $width)));
		}

		$targetWidth = $width;
		$targetHeight = $height;

		if (!$upscale && ($targetWidth > $cropWidth || $targetHeight > $cropHeight)) {
			$targetWidth = $cropWidth;
			$targetHeight = $cropHeight;
		}

		return [
			\intdiv($regionWidth - $cropWidth, 2),
			\intdiv($regionHeight - $cropHeight, 2),
			$cropWidth,
			$cropHeight,
			$targetWidth,
			$targetHeight,
		];
	}


	/**
	 * Map a display-space plan onto the stored (unoriented) image.
	 *
	 * Behavior:
	 * - Maps the source rectangle through the inverse of the orientation.
	 * - Swaps the target size for orientations 5-8, so that orienting the
	 *   rendered result yields the display-space target size.
	 *
	 * @param array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int} $plan Display-space plan.
	 * @param int $orientation EXIF orientation 1-8.
	 * @param int $storedWidth Stored image width.
	 * @param int $storedHeight Stored image height.
	 * @return array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int} Stored-space plan.
	 */
	public static function toStored(array $plan, int $orientation, int $storedWidth, int $storedHeight): array {
		[$x, $y, $width, $height, $targetWidth, $targetHeight] = $plan;

		return match ($orientation) {
			2 => [$storedWidth - $x - $width, $y, $width, $height, $targetWidth, $targetHeight],
			3 => [$storedWidth - $x - $width, $storedHeight - $y - $height, $width, $height, $targetWidth, $targetHeight],
			4 => [$x, $storedHeight - $y - $height, $width, $height, $targetWidth, $targetHeight],
			5 => [$y, $x, $height, $width, $targetHeight, $targetWidth],
			6 => [$y, $storedHeight - $x - $width, $height, $width, $targetHeight, $targetWidth],
			7 => [$storedWidth - $y - $height, $storedHeight - $x - $width, $height, $width, $targetHeight, $targetWidth],
			8 => [$storedWidth - $y - $height, $x, $height, $width, $targetHeight, $targetWidth],
			default => $plan,
		};
	}


	/**
	 * Integer division rounded half up, for non-negative operands.
	 *
	 * @param int $numerator Non-negative numerator.
	 * @param int $denominator Positive denominator.
	 * @return int Rounded quotient.
	 */
	private static function roundDiv(int $numerator, int $denominator): int {
		return \intdiv(2 * $numerator + $denominator, 2 * $denominator);
	}


}

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

namespace CitOmni\Image\Color;

use CitOmni\Image\Enum\ColorSpace;

/**
 * IccProfile: Classify ICC profiles without a color engine.
 *
 * Decides whether an embedded profile is equivalent to sRGB (no conversion
 * needed, so even a runtime without color management can process it) or
 * names a known space such as Display P3 or Adobe RGB.
 *
 * Behavior:
 * - RGB matrix/TRC profiles are compared on their D50 colorants (rXYZ, gXYZ,
 *   bXYZ) and on all three tone curves, sampled at fixed points. Both must
 *   match: sRGB colorants with a linear curve (scRGB) are not sRGB.
 * - Gray profiles count as sRGB-equivalent when their curve is sRGB's.
 * - Profiles with a device-to-PCS LUT (A2B0-2, D2B0-2) are not provably
 *   equivalent to their matrix/TRC tags, which a color engine bypasses for
 *   them: such RGB profiles are reported as Rgb, gray ones as Gray.
 * - Malformed, truncated, or inconsistent data yields Other; nothing throws.
 *   That includes duplicate tag signatures and tag data overlapping the
 *   header or tag table.
 *
 * Notes:
 * - Pure: no IO, no engine. Profiles are untrusted data; every offset and
 *   count is bounds-checked against the data.
 * - Tolerances were derived from independent sRGB profiles (HP/IEC 61966-2.1
 *   derivatives, Ghostscript, LittleCMS, Compact ICC), which differ by at
 *   most about 0.0002 in colorants; Display P3 and Adobe RGB differ from sRGB
 *   by at least 0.08.
 */
final class IccProfile {

	private const HEADER_SIZE = 128;
	private const MAX_TAGS = 1024;
	private const MAX_CURVE_ENTRIES = 65_536;
	private const COLORANT_TOLERANCE = 0.002;
	private const CURVE_TOLERANCE = 0.004;
	private const CURVE_SAMPLES = [0.02, 0.1, 0.25, 0.5, 0.75, 0.95];

	// Device-to-PCS transforms (AToB LUTs, v5 DToB). A color engine prefers
	// them over matrix/TRC tags, so a profile carrying any of them is never
	// judged by its matrix alone.
	private const DEVICE_TO_PCS_TAGS = ['A2B0', 'A2B1', 'A2B2', 'D2B0', 'D2B1', 'D2B2'];

	// D50-adapted colorants [rXYZ, gXYZ, bXYZ] and tone curve per known space.
	private const KNOWN = [
		'srgb' => [[[0.43607, 0.22249, 0.01392], [0.38515, 0.71687, 0.09708], [0.14307, 0.06061, 0.71410]], 'srgb'],
		'display-p3' => [[[0.51512, 0.24120, -0.00105], [0.29198, 0.69225, 0.04189], [0.15710, 0.06657, 0.78407]], 'srgb'],
		'adobe-rgb' => [[[0.60974, 0.31111, 0.01947], [0.20528, 0.62567, 0.06087], [0.14919, 0.06322, 0.74457]], 'adobe'],
	];


	/**
	 * Return the profile's data color space.
	 *
	 * @param string $icc Profile bytes.
	 * @return string|null "rgb", "gray", "cmyk", or null for anything else or malformed data
	 *                     (header or tag table).
	 */
	public static function dataSpace(string $icc): ?string {
		if (!self::valid($icc) || self::tags($icc) === null) {
			return null;
		}

		return match (\substr($icc, 16, 4)) {
			'RGB ' => 'rgb',
			'GRAY' => 'gray',
			'CMYK' => 'cmyk',
			default => null,
		};
	}


	/**
	 * Classify a profile.
	 *
	 * @param string $icc Profile bytes.
	 * @return ColorSpace Classification; Other for malformed data.
	 */
	public static function classify(string $icc): ColorSpace {
		$space = self::dataSpace($icc);

		if ($space === 'cmyk') {
			return ColorSpace::Cmyk;
		}

		if ($space === null) {
			return ColorSpace::Other;
		}

		$tags = self::tags($icc);

		if ($tags === null) {
			return ColorSpace::Other;
		}

		$lut = \array_intersect_key($tags, \array_flip(self::DEVICE_TO_PCS_TAGS)) !== [];

		if ($space === 'gray') {
			if ($lut) {
				return ColorSpace::Gray;
			}

			$curve = self::tag($icc, $tags, 'kTRC');

			return ($curve !== null && self::curveMatches($curve, 'srgb')) ? ColorSpace::Srgb : ColorSpace::Gray;
		}

		// RGB: matrix/TRC profiles only; LUT-based ones are not provably sRGB.
		if ($lut) {
			return ColorSpace::Rgb;
		}

		$colorants = [];
		$curves = [];

		foreach (['r', 'g', 'b'] as $channel) {
			$xyz = self::xyz(self::tag($icc, $tags, $channel . 'XYZ'));
			$curve = self::tag($icc, $tags, $channel . 'TRC');

			if ($xyz === null || $curve === null) {
				return ColorSpace::Rgb;
			}

			$colorants[] = $xyz;
			$curves[] = $curve;
		}

		foreach (self::KNOWN as $name => [$expected, $curveKind]) {
			if (!self::colorantsMatch($colorants, $expected)) {
				continue;
			}

			foreach ($curves as $curve) {
				if (!self::curveMatches($curve, $curveKind)) {
					return ColorSpace::Rgb;
				}
			}

			return ColorSpace::from($name);
		}

		return ColorSpace::Rgb;
	}


	// ----------------------------------------------------------------
	// Structure
	// ----------------------------------------------------------------

	/**
	 * Validate the header: size, signature, declared length.
	 *
	 * @param string $icc Profile bytes.
	 * @return bool True when the header is usable.
	 */
	private static function valid(string $icc): bool {
		$length = \strlen($icc);

		if ($length < self::HEADER_SIZE + 4 || \substr($icc, 36, 4) !== 'acsp') {
			return false;
		}

		$declared = \unpack('N', $icc)[1];

		return $declared >= self::HEADER_SIZE + 4 && $declared <= $length;
	}


	/**
	 * Read the tag table.
	 *
	 * Behavior:
	 * - Malformed (null): a count above MAX_TAGS, a table beyond the declared
	 *   size, tag data outside the profile or overlapping the header or tag
	 *   table, or a signature that occurs twice (ICC.1 forbids duplicates; a
	 *   parser picking one of them could disagree with a color engine).
	 * - Tags sharing the same data (rTRC, gTRC, bTRC) are valid.
	 * - 4-byte alignment of tag data is not enforced: it does not affect
	 *   parsing, and color engines accept unaligned profiles.
	 *
	 * @param string $icc Validated profile bytes.
	 * @return array<string, array{0:int, 1:int}>|null Tag signature => [offset, size], or null when malformed.
	 */
	private static function tags(string $icc): ?array {
		$declared = \unpack('N', $icc)[1];
		$count = \unpack('N', $icc, self::HEADER_SIZE)[1];

		if ($count > self::MAX_TAGS || self::HEADER_SIZE + 4 + 12 * $count > $declared) {
			return null;
		}

		$tags = [];
		$tableEnd = self::HEADER_SIZE + 4 + 12 * $count;

		for ($i = 0; $i < $count; $i++) {
			[, $offset, $size] = \array_values(\unpack('a4sig/Noffset/Nsize', $icc, self::HEADER_SIZE + 4 + 12 * $i));
			$signature = \substr($icc, self::HEADER_SIZE + 4 + 12 * $i, 4);

			if ($size < 8 || $offset < $tableEnd || $offset > $declared - $size || isset($tags[$signature])) {
				return null;
			}

			$tags[$signature] = [$offset, $size];
		}

		return $tags;
	}


	/**
	 * Return a tag's data.
	 *
	 * @param string $icc Profile bytes.
	 * @param array<string, array{0:int, 1:int}> $tags Tag table.
	 * @param string $signature Tag signature.
	 * @return string|null Tag data, or null when absent.
	 */
	private static function tag(string $icc, array $tags, string $signature): ?string {
		return isset($tags[$signature]) ? \substr($icc, $tags[$signature][0], $tags[$signature][1]) : null;
	}


	/**
	 * Parse an XYZType tag holding one XYZ number.
	 *
	 * @param string|null $data Tag data.
	 * @return array{0:float, 1:float, 2:float}|null XYZ, or null when malformed.
	 */
	private static function xyz(?string $data): ?array {
		if ($data === null || \strlen($data) < 20 || \strncmp($data, 'XYZ ', 4) !== 0) {
			return null;
		}

		return [self::s15f16($data, 8), self::s15f16($data, 12), self::s15f16($data, 16)];
	}


	// ----------------------------------------------------------------
	// Comparison
	// ----------------------------------------------------------------

	/**
	 * @param list<array{0:float, 1:float, 2:float}> $actual Colorants found.
	 * @param list<list<float>> $expected Reference colorants.
	 * @return bool True when every component is within tolerance.
	 */
	private static function colorantsMatch(array $actual, array $expected): bool {
		foreach ($expected as $i => $reference) {
			foreach ($reference as $k => $value) {
				if (\abs($actual[$i][$k] - $value) > self::COLORANT_TOLERANCE) {
					return false;
				}
			}
		}

		return true;
	}


	/**
	 * Whether a curve tag matches a reference tone curve at the sample points.
	 *
	 * @param string $data curveType or parametricCurveType tag data.
	 * @param string $kind "srgb" (IEC 61966-2.1) or "adobe" (gamma 563/256).
	 * @return bool True when every sample is within tolerance.
	 */
	private static function curveMatches(string $data, string $kind): bool {
		foreach (self::CURVE_SAMPLES as $x) {
			$expected = $kind === 'srgb'
				? ($x <= 0.04045 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4)
				: $x ** (563 / 256);
			$actual = self::evaluate($data, $x);

			if ($actual === null || \abs($actual - $expected) > self::CURVE_TOLERANCE) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Evaluate a curveType ('curv') or parametricCurveType ('para') tag.
	 *
	 * @param string $data Tag data.
	 * @param float $x Input in [0, 1].
	 * @return float|null Output, or null when the tag is malformed or not a curve.
	 */
	private static function evaluate(string $data, float $x): ?float {
		$length = \strlen($data);

		if ($length >= 12 && \strncmp($data, 'curv', 4) === 0) {
			$count = \unpack('N', $data, 8)[1];

			if ($count === 0) {
				return $x;
			}

			if ($count === 1) {
				return $length >= 14 ? $x ** (\unpack('n', $data, 12)[1] / 256) : null;
			}

			if ($count > self::MAX_CURVE_ENTRIES || 12 + 2 * $count > $length) {
				return null;
			}

			$position = $x * ($count - 1);
			$index = (int)\floor($position);
			$next = \min($index + 1, $count - 1);
			$low = \unpack('n', $data, 12 + 2 * $index)[1];
			$high = \unpack('n', $data, 12 + 2 * $next)[1];

			return ($low + ($high - $low) * ($position - $index)) / 65535;
		}

		if ($length >= 16 && \strncmp($data, 'para', 4) === 0) {
			$type = \unpack('n', $data, 8)[1];
			$parameters = [1, 3, 4, 5, 7][$type] ?? null;

			if ($parameters === null || 12 + 4 * $parameters > $length) {
				return null;
			}

			$p = [];

			for ($i = 0; $i < $parameters; $i++) {
				$p[] = self::s15f16($data, 12 + 4 * $i);
			}

			[$g, $a, $b, $c, $d, $e, $f] = $p + [1 => 1.0, 0.0, 0.0, 0.0, 0.0, 0.0];
			$power = static fn(float $base): float => \max(0.0, $base) ** $g;

			return match ($type) {
				0 => $power($x),
				1 => ($a != 0.0 && $x >= -$b / $a) ? $power($a * $x + $b) : 0.0,
				2 => ($a != 0.0 && $x >= -$b / $a) ? $power($a * $x + $b) + $c : $c,
				3 => $x >= $d ? $power($a * $x + $b) : $c * $x,
				default => $x >= $d ? $power($a * $x + $b) + $e : $c * $x + $f,
			};
		}

		return null;
	}


	/**
	 * Read a signed 15.16 fixed-point number.
	 *
	 * @param string $data Buffer.
	 * @param int $offset Offset of the 4-byte value.
	 * @return float Value.
	 */
	private static function s15f16(string $data, int $offset): float {
		$raw = \unpack('N', $data, $offset)[1];

		return ($raw >= 0x80000000 ? $raw - 0x100000000 : $raw) / 65536;
	}


}

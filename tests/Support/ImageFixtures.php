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

namespace CitOmni\Image\Tests\Support;

/**
 * Deterministic image fixtures built in memory with GD and hand-written bytes.
 *
 * No binary fixtures are stored in the repository; every builder produces
 * the same bytes for the same GD build.
 */
final class ImageFixtures {

	public const RED = [255, 0, 0];
	public const GREEN = [0, 255, 0];
	public const BLUE = [0, 0, 255];
	public const YELLOW = [255, 255, 0];


	/**
	 * Stored image with four colored quadrants: red TL, green TR, blue BL, yellow BR.
	 */
	public static function quadrants(int $width = 301, int $height = 199): \GdImage {
		$image = \imagecreatetruecolor($width, $height);
		$halfWidth = \intdiv($width, 2);
		$halfHeight = \intdiv($height, 2);
		$colors = [self::RED, self::GREEN, self::BLUE, self::YELLOW];
		$boxes = [
			[0, 0, $halfWidth - 1, $halfHeight - 1],
			[$halfWidth, 0, $width - 1, $halfHeight - 1],
			[0, $halfHeight, $halfWidth - 1, $height - 1],
			[$halfWidth, $halfHeight, $width - 1, $height - 1],
		];

		foreach ($boxes as $i => [$x1, $y1, $x2, $y2]) {
			\imagefilledrectangle($image, $x1, $y1, $x2, $y2, \imagecolorallocate($image, ...$colors[$i]));
		}

		return $image;
	}


	/**
	 * Quadrant JPEG, optionally with an EXIF orientation and an ICC APP2 segment.
	 */
	public static function jpeg(?int $orientation = null, bool $bigEndian = false, bool $icc = false, int $width = 301, int $height = 199): string {
		$jpeg = self::encode(self::quadrants($width, $height), 'jpeg');
		$segments = '';

		if ($orientation !== null) {
			$segments .= self::exifSegment($orientation, $bigEndian);
		}

		if ($icc) {
			// A real sRGB profile: present, readable, and needing no conversion.
			$segments .= self::segment(0xE2, "ICC_PROFILE\x00\x01\x01" . \CitOmni\Image\Color\Profiles::srgb());
		}

		return \substr($jpeg, 0, 2) . $segments . \substr($jpeg, 2);
	}


	/**
	 * APP1 EXIF segment with a single IFD0 Orientation entry.
	 */
	public static function exifSegment(int $orientation, bool $bigEndian = false, int $type = 3): string {
		return self::segment(0xE1, "Exif\x00\x00" . self::tiff([[0x0112, $type, 1, $orientation]], $bigEndian));
	}


	/**
	 * Raw TIFF structure with one IFD.
	 *
	 * @param list<array{0:int, 1:int, 2:int, 3:int}> $entries [tag, type, count, short value]
	 */
	public static function tiff(array $entries, bool $bigEndian = false, int $nextIfd = 0): string {
		[$short, $long] = $bigEndian ? ['n', 'N'] : ['v', 'V'];
		$data = ($bigEndian ? 'MM' : 'II') . \pack($short, 42) . \pack($long, 8) . \pack($short, \count($entries));

		foreach ($entries as [$tag, $type, $count, $value]) {
			$data .= \pack($short, $tag) . \pack($short, $type) . \pack($long, $count) . \pack($short, $value) . "\x00\x00";
		}

		return $data . \pack($long, $nextIfd);
	}


	/**
	 * Minimal uncompressed TIFF file (getimagesize-readable).
	 */
	public static function tiffFile(int $width, int $height, int $orientation): string {
		return self::tiff([
			[0x0100, 3, 1, $width],
			[0x0101, 3, 1, $height],
			[0x0112, 3, 1, $orientation],
		]);
	}


	/**
	 * JPEG marker segment.
	 */
	public static function segment(int $marker, string $payload): string {
		return "\xFF" . \chr($marker) . \pack('n', \strlen($payload) + 2) . $payload;
	}


	/**
	 * 200x100 PNG: left half fully transparent, right half opaque magenta.
	 */
	public static function alphaPng(): string {
		$image = \imagecreatetruecolor(200, 100);
		\imagealphablending($image, false);
		\imagesavealpha($image, true);
		\imagefilledrectangle($image, 0, 0, 99, 99, \imagecolorallocatealpha($image, 0, 0, 0, 127));
		\imagefilledrectangle($image, 100, 0, 199, 99, \imagecolorallocatealpha($image, 255, 0, 255, 0));

		return self::encode($image, 'png');
	}


	/**
	 * 100x100 palette GIF: TL quarter transparent (key is black), right half
	 * opaque black from a separate index, BL quarter white.
	 */
	public static function transparentGif(): string {
		return self::encode(self::paletteImage(), 'gif');
	}


	/**
	 * Same picture as transparentGif(), as a palette PNG with tRNS.
	 */
	public static function palettePng(): string {
		return self::encode(self::paletteImage(), 'png');
	}


	/**
	 * Two-frame animated GIF, 6x4 pixels: first frame red, second frame blue.
	 *
	 * Built from two GD single-frame GIFs sharing one palette: header and
	 * global color table from the first, then both image blocks.
	 */
	public static function animatedGif(): string {
		$frames = [];

		foreach ([0, 1] as $fill) {
			$image = \imagecreate(6, 4);
			$colors = [\imagecolorallocate($image, 255, 0, 0), \imagecolorallocate($image, 0, 0, 255)];
			\imagefilledrectangle($image, 0, 0, 5, 3, $colors[$fill]);
			$frames[] = self::encode($image, 'gif');
		}

		$tableSize = 3 * (1 << ((\ord($frames[0][10]) & 0x07) + 1));
		$imageBlock = static fn(string $gif): string => \substr($gif, 13 + $tableSize, -1);

		return \substr($frames[0], 0, 13 + $tableSize) . $imageBlock($frames[0]) . $imageBlock($frames[1]) . "\x3B";
	}


	/**
	 * Quadrant PNG with an acTL chunk inserted after IHDR (APNG signature).
	 */
	public static function apng(): string {
		$png = self::encode(self::quadrants(40, 30), 'png');

		// IHDR is the first chunk: 8-byte signature + 25-byte chunk.
		return \substr($png, 0, 33) . self::pngChunk('acTL', \pack('NN', 1, 0)) . \substr($png, 33);
	}


	/**
	 * PNG with an iCCP chunk inserted after IHDR.
	 */
	public static function iccPng(): string {
		$png = self::encode(self::quadrants(40, 30), 'png');

		return \substr($png, 0, 33) . self::pngChunk('iCCP', "test\x00\x00" . \gzcompress('not a real profile')) . \substr($png, 33);
	}


	/**
	 * PNG header claiming the given size, followed by a bogus IDAT.
	 */
	public static function pngHeader(int $width, int $height): string {
		return "\x89PNG\r\n\x1A\n"
			. self::pngChunk('IHDR', \pack('NN', $width, $height) . "\x08\x06\x00\x00\x00")
			. self::pngChunk('IDAT', 'x')
			. self::pngChunk('IEND', '');
	}


	/**
	 * WebP extended-format header with the given VP8X flags.
	 */
	public static function webpVp8x(int $flags, int $width = 64, int $height = 48): string {
		$body = 'VP8X' . \pack('V', 10) . \chr($flags) . "\x00\x00\x00"
			. \substr(\pack('V', $width - 1), 0, 3) . \substr(\pack('V', $height - 1), 0, 3);

		return 'RIFF' . \pack('V', 4 + \strlen($body)) . 'WEBP' . $body;
	}


	/**
	 * ISOBMFF file starting with an ftyp box.
	 *
	 * @param list<string> $compatible Compatible brands.
	 */
	public static function ftyp(string $major, array $compatible): string {
		$payload = $major . "\x00\x00\x00\x00" . \implode('', $compatible);

		return \pack('N', 8 + \strlen($payload)) . 'ftyp' . $payload . \str_repeat("\x00", 32);
	}


	/**
	 * Header-only HEIF-family container (no image data) with primary item 1.
	 *
	 * @param list<array{0:string, 1:mixed}> $properties Extra properties of the primary item after ispe, in ipma order:
	 *   ['irot', angle 0-3], ['imir', mode 0|1], ['clap', list of 8 ints], ['colr', 'prof'|'nclx'|[primaries, transfer]], ['auxC', aux type]
	 * @param list<string> $compatible Compatible brands.
	 * @param string|null $alphaItem null: none; 'linked': item 2 is an alpha plane with auxl -> 1;
	 *   'unlinked': item 2 is an alpha plane of item 3, not of the primary.
	 */
	public static function heifContainer(string $brand, int $width, int $height, array $properties = [], array $compatible = ['mif1'], ?string $alphaItem = null): string {
		$ipco = self::isoBox('ispe', "\x00\x00\x00\x00" . \pack('NN', $width, $height));
		$associations = "\x01";

		foreach ($properties as $i => [$type, $value]) {
			$ipco .= self::heifProperty($type, $value);
			// Transforms are marked essential (high bit), as the specification requires.
			$associations .= \chr(($i + 2) | (\in_array($type, ['irot', 'imir', 'clap'], true) ? 0x80 : 0));
		}

		$entries = \pack('n', 1) . \chr(\count($properties) + 1) . $associations;
		$iref = '';

		if ($alphaItem !== null) {
			$ipco .= self::heifProperty('auxC', 'urn:mpeg:hevc:2015:auxid:1');
			$entries .= \pack('n', 2) . "\x01" . \chr(\count($properties) + 2);
			$target = $alphaItem === 'linked' ? 1 : 3;
			$iref = self::isoBox('iref', "\x00\x00\x00\x00" . self::isoBox('auxl', \pack('nnn', 2, 1, $target)));
		}

		$ipma = "\x00\x00\x00\x00" . \pack('N', $alphaItem !== null ? 2 : 1) . $entries;
		$meta = "\x00\x00\x00\x00"
			. self::isoBox('hdlr', "\x00\x00\x00\x00\x00\x00\x00\x00pict" . \str_repeat("\x00", 13))
			. self::isoBox('pitm', "\x00\x00\x00\x00" . \pack('n', 1))
			. self::isoBox('iprp', self::isoBox('ipco', $ipco) . self::isoBox('ipma', $ipma))
			. $iref;

		return self::isoBox('ftyp', $brand . "\x00\x00\x00\x00" . $brand . \implode('', $compatible)) . self::isoBox('meta', $meta);
	}


	/**
	 * One HEIF item property box.
	 */
	private static function heifProperty(string $type, mixed $value): string {
		return self::isoBox($type, match ($type) {
			'irot', 'imir' => \chr($value),
			'clap' => \pack('N8', ...$value),
			'colr' => \is_array($value)
				? 'nclx' . \pack('nnn', $value[0], $value[1], $value[2] ?? 6) . "\x80"
				: $value . ($value === 'prof' ? 'fake profile' : "\x00\x01\x00\x0D\x00\x06\x80"),
			'auxC' => "\x00\x00\x00\x00" . $value . "\x00",
		});
	}


	/**
	 * Inject irot/imir transforms into a real single-image HEIF file.
	 *
	 * Behavior:
	 * - Appends the properties to ipco and associates them with the primary
	 *   item (ipma), growing every enclosing box.
	 * - Shifts iloc offsets that point behind the meta box by the growth.
	 *
	 * @param string $heif Encoded HEIC/AVIF with layout ftyp, meta, mdat.
	 * @param int|null $angle irot angle 0-3 (counter-clockwise quarter turns), or null.
	 * @param int|null $axis imir axis 0|1, or null.
	 */
	public static function withHeifTransforms(string $heif, ?int $angle, ?int $axis = null): string {
		$boxes = [];

		if ($angle !== null) {
			$boxes[] = self::isoBox('irot', \chr($angle));
		}

		if ($axis !== null) {
			$boxes[] = self::isoBox('imir', \chr($axis));
		}

		return self::withHeifProperties($heif, ...$boxes);
	}


	/**
	 * Set a chromaticity coordinate on either Imagick build (ImageMagick 7 takes z).
	 */
	public static function chromaticity(\Imagick $image, string $method, float $x, float $y): void {
		if ((new \ReflectionMethod(\Imagick::class, $method))->getNumberOfRequiredParameters() >= 3) {
			$image->{$method}($x, $y, 1.0 - $x - $y);
		} else {
			$image->{$method}($x, $y);
		}
	}


	/**
	 * Give a real HEIF file nclx color information (CICP code points).
	 *
	 * An existing nclx box (libheif writes one for AVIF) is patched in place;
	 * otherwise a new colr property is injected.
	 */
	public static function withHeifNclx(string $heif, int $primaries, int $transfer, int $matrix = 6): string {
		$at = \strpos($heif, 'colrnclx');

		if ($at !== false) {
			return \substr_replace($heif, \pack('nnn', $primaries, $transfer, $matrix), $at + 8, 6);
		}

		return self::withHeifProperties($heif, self::isoBox('colr', 'nclx' . \pack('nnn', $primaries, $transfer, $matrix) . "\x80"));
	}


	/**
	 * Append property boxes to a real single-image HEIF file and associate
	 * them (as essential) with the primary item.
	 *
	 * Behavior:
	 * - Grows ipco, ipma, iprp, and meta; shifts iloc offsets that point
	 *   behind the meta box.
	 *
	 * @param string $heif Encoded HEIC/AVIF with layout ftyp, meta, mdat.
	 */
	public static function withHeifProperties(string $heif, string ...$boxes): string {
		$added = \implode('', $boxes);
		$newProperties = \count($boxes);
		[$metaAt, $metaEnd] = self::boxRange($heif, 0, \strlen($heif), 'meta');
		[$iprpAt, $iprpEnd] = self::boxRange($heif, $metaAt + 12, $metaEnd, 'iprp');
		[$ipcoAt, $ipcoEnd] = self::boxRange($heif, $iprpAt + 8, $iprpEnd, 'ipco');
		[$ipmaAt, $ipmaEnd] = self::boxRange($heif, $iprpAt + 8, $iprpEnd, 'ipma');
		[$ilocAt, $ilocEnd] = self::boxRange($heif, $metaAt + 12, $metaEnd, 'iloc');

		// -- ipma: one entry for the primary item is assumed (ImageMagick output) --
		$ipma = \substr($heif, $ipmaAt, $ipmaEnd - $ipmaAt);
		$wide = (\ord($ipma[11]) & 0x01) === 1;
		$idSize = \ord($ipma[8]) < 1 ? 2 : 4;
		$countAt = 16 + $idSize;
		$propertyCount = 0;

		for ($at = $ipcoAt + 8; $at < $ipcoEnd; $at += \unpack('N', $heif, $at)[1]) {
			$propertyCount++;
		}

		$associations = '';

		for ($i = 1; $i <= $newProperties; $i++) {
			$associations .= $wide ? \pack('n', 0x8000 | ($propertyCount + $i)) : \chr(0x80 | ($propertyCount + $i));
		}

		$ipma[$countAt] = \chr(\ord($ipma[$countAt]) + $newProperties);
		$ipma = \substr_replace($ipma, \pack('N', \strlen($ipma) + \strlen($associations)), 0, 4) . $associations;
		$growth = \strlen($added) + \strlen($associations);

		// -- iloc: shift absolute offsets that point behind the meta box --------
		$iloc = \substr($heif, $ilocAt, $ilocEnd - $ilocAt);
		$version = \ord($iloc[8]);
		$offsetSize = \ord($iloc[12]) >> 4;
		$lengthSize = \ord($iloc[12]) & 0x0F;
		$baseSize = \ord($iloc[13]) >> 4;
		$indexSize = $version >= 1 ? \ord($iloc[13]) & 0x0F : 0;
		$at = 14;
		$items = $version < 2 ? \unpack('n', $iloc, $at)[1] : \unpack('N', $iloc, $at)[1];
		$at += $version < 2 ? 2 : 4;
		$shift = static function (string &$data, int $at, int $size) use ($metaEnd, $growth): void {
			if ($size === 0) {
				return;
			}

			$value = $size === 4 ? \unpack('N', $data, $at)[1] : \unpack('J', $data, $at)[1];

			if ($value >= $metaEnd) {
				$data = \substr_replace($data, \pack($size === 4 ? 'N' : 'J', $value + $growth), $at, $size);
			}
		};

		for ($i = 0; $i < $items; $i++) {
			$at += ($version < 2 ? 2 : 4) + ($version >= 1 ? 2 : 0) + 2;
			$shift($iloc, $at, $baseSize);
			$at += $baseSize;
			$extents = \unpack('n', $iloc, $at)[1];
			$at += 2;

			for ($e = 0; $e < $extents; $e++) {
				$at += $indexSize;

				if ($baseSize === 0) {
					$shift($iloc, $at, $offsetSize);
				}

				$at += $offsetSize + $lengthSize;
			}
		}

		// -- Reassemble, growing ipco, iprp and meta -----------------------------
		$grow = static fn(string $data, int $at, int $by): string => \substr_replace($data, \pack('N', \unpack('N', $data, $at)[1] + $by), $at, 4);
		$out = \substr($heif, 0, $ipcoEnd) . $added . \substr($heif, $ipcoEnd, $ipmaAt - $ipcoEnd) . $ipma . \substr($heif, $ipmaEnd);
		$out = $grow($out, $ipcoAt, \strlen($added));
		$out = $grow($out, $iprpAt, $growth);
		$out = $grow($out, $metaAt, $growth);
		$ilocShift = $ilocAt > $iprpAt ? $growth : 0;

		return \substr_replace($out, $iloc, $ilocAt + $ilocShift, $ilocEnd - $ilocAt);
	}


	/**
	 * Find a child box by type and return [start, end].
	 *
	 * @return array{0:int, 1:int}
	 */
	private static function boxRange(string $data, int $start, int $end, string $type): array {
		for ($at = $start; $at + 8 <= $end; $at += \unpack('N', $data, $at)[1]) {
			if (\substr($data, $at + 4, 4) === $type) {
				return [$at, $at + \unpack('N', $data, $at)[1]];
			}
		}

		throw new \RuntimeException('Fixture: box "' . $type . '" not found.');
	}


	/**
	 * ISOBMFF box.
	 */
	public static function isoBox(string $type, string $payload): string {
		return \pack('N', 8 + \strlen($payload)) . $type . $payload;
	}


	/**
	 * Encode a GD image with GD's own writer.
	 */
	public static function encode(\GdImage $image, string $format): string {
		\ob_start();

		match ($format) {
			'jpeg' => \imagejpeg($image, null, 95),
			'png' => \imagepng($image),
			'gif' => \imagegif($image),
			'webp' => \imagewebp($image, null, 90),
			'bmp' => \imagebmp($image, null, false),
		};

		return (string)\ob_get_clean();
	}


	/**
	 * PNG chunk with CRC.
	 */
	public static function pngChunk(string $type, string $data): string {
		return \pack('N', \strlen($data)) . $type . $data . \pack('N', \crc32($type . $data));
	}


	/**
	 * Palette image used by transparentGif() and palettePng().
	 */
	private static function paletteImage(): \GdImage {
		$image = \imagecreate(100, 100);
		$transparent = \imagecolorallocate($image, 0, 0, 0);
		$white = \imagecolorallocate($image, 255, 255, 255);
		$black = \imagecolorallocate($image, 0, 0, 0);
		\imagecolortransparent($image, $transparent);
		\imagefilledrectangle($image, 0, 0, 99, 99, $transparent);
		\imagefilledrectangle($image, 50, 0, 99, 99, $black);
		\imagefilledrectangle($image, 0, 50, 49, 99, $white);

		return $image;
	}


}

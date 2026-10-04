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

use CitOmni\Image\Enum\ImageFormat;

/**
 * ImageHeader: Backend-independent metadata scan of image container headers.
 *
 * Reads only the structures needed before any pixel is decoded: EXIF
 * orientation, whether more than one frame is present, whether the format
 * carries transparency, the embedded ICC profile (bytes, bounded), and
 * untagged CMYK data.
 *
 * Behavior:
 * - Bytes are pulled through a reader callback, so the same code serves file
 *   streams and in-memory data with identical results.
 * - Malformed or truncated structures never throw; the affected field falls
 *   back to its neutral value (orientation 1, false) or null.
 * - Every size, offset, and rational read from a container is treated as an
 *   untrusted integer: box sizes are bounded by the real data length, large
 *   metadata reads are capped, and arithmetic is range-checked so it cannot
 *   overflow.
 * - Fields that cannot be determined reliably for a format are null.
 *
 * Notes:
 * - Pure: the only IO is whatever the injected reader performs.
 * - JPEG segments are only considered when they start within the first
 *   JPEG_SCAN_BYTES bytes. PNG chunks are walked up to the first IDAT. GIF
 *   blocks are walked until a second frame is found or the trailer is reached.
 * - EXIF orientation is honored for JPEG and TIFF. Other formats report 1;
 *   HEIF-family container transforms are reported separately by heif().
 *
 * Typical usage:
 *   $meta = ImageHeader::scan(
 *   	ImageFormat::Jpeg,
 *   	fn(int $offset, int $length): string => (string)\substr($data, $offset, $length),
 *   	\strlen($data)
 *   );
 */
final class ImageHeader {

	public const JPEG_SCAN_BYTES = 262_144;

	// Upper bound on an embedded ICC profile read into memory. Large CMYK
	// print profiles reach 1-3 MiB; a larger one is reported but not read.
	public const ICC_LIMIT = 4_194_304;

	// JPEG marker segments are walked up to this offset, so ICC profiles split
	// over many APP2 segments can be collected. EXIF orientation still only
	// counts within JPEG_SCAN_BYTES.
	private const JPEG_METADATA_BYTES = 6_291_456;

	// Upper bound on IFD entries examined; real IFD0s hold a few dozen.
	private const TIFF_MAX_ENTRIES = 4096;

	private const HEIC_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs'];

	// HEIF image-sequence brands (ISO/IEC 23008-12, MP4RA): generic (msf1),
	// AV1 (avis), HEVC (hevc, hevx), and layered HEVC (hevm, hevs). Still-image
	// brands such as heic, heix, heim, heis, avif, and mif1 are not listed.
	private const SEQUENCE_BRANDS = ['avis', 'msf1', 'hevc', 'hevx', 'hevm', 'hevs'];

	// Upper bound on ISOBMFF boxes examined per container level.
	private const BOX_LIMIT = 4096;

	// Upper bound on a HEIF metadata box body read into memory (ipma, iref).
	// Real files use a few hundred bytes; larger declared sizes are rejected.
	private const HEIF_METADATA_LIMIT = 1_048_576;

	// Upper bound on a leading ftyp box (about 1000 brands). The whole box is
	// read; a larger one is treated as malformed, never as "no such brand".
	private const FTYP_LIMIT = 4_096;

	// Upper bound on clean-aperture denominators after reduction. Real files
	// use 1 or 2. The bound keeps every intermediate product of the exact
	// rational arithmetic below 2^62, so no integer can overflow to float.
	private const CLAP_DENOMINATOR_LIMIT = 16_384;


	/**
	 * Scan container metadata for a detected format.
	 *
	 * @param ImageFormat $format Format detected from content.
	 * @param \Closure(int, int): string $read Returns up to $length bytes at $offset; shorter or empty at EOF.
	 * @param int $length Total length of the data in bytes.
	 * @return array{orientation:int, multi_frame:bool|null, alpha:bool|null, icc_profile:bool|null, icc:string|null, cmyk:bool} Metadata.
	 *   icc: the embedded profile when present and readable within ICC_LIMIT; icc_profile tells whether one is present at all.
	 *   cmyk: the pixel data is CMYK/YCCK (JPEG with four components, TIFF photometric "separated").
	 */
	public static function scan(ImageFormat $format, \Closure $read, int $length): array {
		return match ($format) {
			ImageFormat::Jpeg => self::scanJpeg($read),
			ImageFormat::Png => self::scanPng($read),
			ImageFormat::Gif => self::scanGif($read),
			ImageFormat::Webp => self::scanWebp($read),
			ImageFormat::Avif, ImageFormat::Heic => self::scanHeif($read, $length),
			ImageFormat::Tiff => self::scanTiff($read),
			ImageFormat::Bmp => ['orientation' => 1, 'multi_frame' => false, 'alpha' => null, 'icc_profile' => null, 'icc' => null, 'cmyk' => false],
		};
	}


	/**
	 * Identify format and picture size of encoded data.
	 *
	 * Behavior:
	 * - Uses the getimagesize() result for the same data; falls back to HEIC
	 *   brand detection when getimagesize() does not recognize the data.
	 * - For HEIF-family formats the size is the clean, container-oriented
	 *   picture, i.e. what a conforming decoder displays.
	 *
	 * @param \Closure(int, int): string $read Reader over the same data.
	 * @param array<int|string, mixed>|false $info getimagesize()/getimagesizefromstring() result.
	 * @param int $length Total length of the data in bytes.
	 * @return array{format:ImageFormat, width:int, height:int}|null Identity, or null when unrecognized.
	 */
	public static function identify(\Closure $read, array|false $info, int $length): ?array {
		$format = \is_array($info)
			? ImageFormat::fromImageType((int)$info[2])
			: (self::isHeic($read, $length) ? ImageFormat::Heic : null);

		if ($format === null) {
			return null;
		}

		if ($format === ImageFormat::Avif || $format === ImageFormat::Heic) {
			$heif = self::heif($read, $length);

			if ($heif === null) {
				return null;
			}

			[$width, $height] = Geometry::displaySize($heif['region'][2], $heif['region'][3], $heif['orientation']);

			return ['format' => $format, 'width' => $width, 'height' => $height];
		}

		return \is_array($info) ? ['format' => $format, 'width' => (int)$info[0], 'height' => (int)$info[1]] : null;
	}


	/**
	 * Read primary-item geometry from a HEIF-family container (HEIC, AVIF).
	 *
	 * Behavior:
	 * - Locates the primary item (pitm) and its associated properties (ipma).
	 * - width/height: coded size from ispe.
	 * - region: clean aperture (clap) as [x, y, width, height] in coded space,
	 *   using libheif's rounding; the full image when absent.
	 * - orientation: irot then imir, expressed as one EXIF-style orientation.
	 *   Per the HEIF specification transforms apply in the order clap, irot,
	 *   imir regardless of their listing order.
	 * - multi_frame: any image-sequence brand (SEQUENCE_BRANDS).
	 * - alpha: an auxiliary item with an alpha auxC property is linked to the
	 *   primary item by an 'auxl' reference.
	 * - icc_profile: the primary item's colr box carries an ICC profile; icc
	 *   holds its bytes when readable within ICC_LIMIT.
	 * - nclx: the primary item's nclx color information (CICP code points),
	 *   when present.
	 *
	 * Notes:
	 * - EXIF orientation inside HEIF files is informational per the
	 *   specification and is deliberately ignored.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $length Total length of the data in bytes; no box may extend beyond it.
	 * @return array{width:int, height:int, region:array{0:int, 1:int, 2:int, 3:int}, orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool, icc:string|null, nclx:array{primaries:int, transfer:int, matrix:int}|null}|null Geometry, or null when the container cannot be parsed.
	 */
	public static function heif(\Closure $read, int $length): ?array {
		// -- 1. Top-level boxes: ftyp brands and the meta box --------------
		$brands = self::ftypBrands($read, $length);

		if ($brands === null) {
			return null;
		}

		$meta = null;
		$offset = 0;

		for ($i = 0; $i < self::BOX_LIMIT && $meta === null; $i++) {
			$box = self::box($read, $offset, $length);

			if ($box === null) {
				break;
			}

			if ($box['type'] === 'meta') {
				// FullBox: skip version and flags.
				$meta = [$box['body'] + 4, $box['end']];
			}

			$offset = $box['end'];
		}

		if ($meta === null) {
			return null;
		}

		// -- 2. Primary item, property container, associations ------------
		$primary = null;
		$properties = [];
		$associations = [];
		$auxiliaryOf = [];

		foreach (self::children($read, $meta[0], $meta[1]) as $box) {
			if ($box['type'] === 'pitm') {
				$data = $read($box['body'], 8);
				$primary = \strlen($data) >= 6 ? (\ord($data[0]) === 0 ? \unpack('n', $data, 4)[1] : (\strlen($data) >= 8 ? \unpack('N', $data, 4)[1] : null)) : null;
			} elseif ($box['type'] === 'iprp') {
				foreach (self::children($read, $box['body'], $box['end']) as $child) {
					if ($child['type'] === 'ipco') {
						$properties = self::children($read, $child['body'], $child['end']);
					} elseif ($child['type'] === 'ipma') {
						if ($child['end'] - $child['body'] > self::HEIF_METADATA_LIMIT) {
							return null;
						}

						$associations = self::ipma($read($child['body'], $child['end'] - $child['body']));
					}
				}
			} elseif ($box['type'] === 'iref') {
				if ($box['end'] - $box['body'] > self::HEIF_METADATA_LIMIT) {
					return null;
				}

				$auxiliaryOf = self::auxiliaryReferences($read($box['body'], $box['end'] - $box['body']));
			}
		}

		if ($primary === null) {
			return null;
		}

		// -- 3. Interpret the primary item's properties ---------------------
		$size = null;
		$clap = null;
		$rotation = 1;
		$mirror = 1;
		$icc = false;
		$profile = null;
		$nclx = null;

		foreach ($associations[$primary] ?? [] as $index) {
			$property = $properties[$index - 1] ?? null;

			if ($property === null) {
				continue;
			}

			$data = $read($property['body'], \min(64, $property['end'] - $property['body']));

			if ($property['type'] === 'ispe' && \strlen($data) >= 12) {
				$size = \unpack('N2', $data, 4);
			} elseif ($property['type'] === 'clap' && \strlen($data) >= 32) {
				$clap = \array_values(\unpack('N8', $data));
			} elseif ($property['type'] === 'irot' && $data !== '') {
				// Counter-clockwise quarter turns: 90 = orientation 8, 180 = 3, 270 = 6.
				$rotation = [1, 8, 3, 6][\ord($data[0]) & 0x03];
			} elseif ($property['type'] === 'imir' && $data !== '') {
				// HEIF 2nd edition "mode", as libheif and libavif >= 1.0 decode it:
				// 0 = top-bottom flip (orientation 4), 1 = left-right flip (orientation 2).
				// The 2017 edition's "axis" wording suggested the opposite.
				$mirror = (\ord($data[0]) & 0x01) === 0 ? 4 : 2;
			} elseif ($property['type'] === 'colr' && \in_array(\substr($data, 0, 4), ['prof', 'rICC'], true)) {
				// Embedded profile; it takes precedence over nclx color information.
				$icc = true;
				$profileSize = $property['end'] - $property['body'] - 4;

				if ($profile === null && $profileSize > 0 && $profileSize <= self::ICC_LIMIT) {
					$bytes = $read($property['body'] + 4, $profileSize);
					$profile = \strlen($bytes) === $profileSize ? $bytes : null;
				}
			} elseif ($property['type'] === 'colr' && \strncmp($data, 'nclx', 4) === 0 && \strlen($data) >= 11 && $nclx === null) {
				$nclx = [
					'primaries' => \unpack('n', $data, 4)[1],
					'transfer' => \unpack('n', $data, 6)[1],
					'matrix' => \unpack('n', $data, 8)[1],
				];
			}
		}

		if ($size === null || $size[1] < 1 || $size[2] < 1) {
			return null;
		}

		[$width, $height] = [$size[1], $size[2]];
		$region = [0, 0, $width, $height];

		if ($clap !== null) {
			$region = self::cleanAperture($clap, $width, $height);

			if ($region === null) {
				return null;
			}
		}

		// -- 4. Alpha: an alpha auxiliary item linked to the primary item ----
		// The auxiliary item carries an alpha auxC property and an 'auxl'
		// reference to the primary item; an alpha plane of another item does
		// not count.
		$alpha = false;

		foreach ($associations as $item => $indices) {
			if ($item === $primary || !\in_array($primary, $auxiliaryOf[$item] ?? [], true)) {
				continue;
			}

			foreach ($indices as $index) {
				$property = $properties[$index - 1] ?? null;

				if ($property === null || $property['type'] !== 'auxC') {
					continue;
				}

				$type = $read($property['body'] + 4, \min(64, $property['end'] - $property['body'] - 4));

				if (\str_contains($type, 'auxiliary:alpha') || \str_contains($type, 'hevc:2015:auxid:1')) {
					$alpha = true;
					break 2;
				}
			}
		}

		return [
			'width' => $width,
			'height' => $height,
			'region' => $region,
			'orientation' => Geometry::then($rotation, $mirror),
			'multi_frame' => \array_intersect($brands, self::SEQUENCE_BRANDS) !== [],
			'alpha' => $alpha,
			'icc_profile' => $icc,
			'icc' => $profile,
			'nclx' => $nclx,
		];
	}


	/**
	 * Detect structurally truncated data for formats whose decoders tolerate it.
	 *
	 * Behavior:
	 * - JPEG: true when no EOI marker follows the first SOS marker. Inside
	 *   entropy-coded data 0xFF is always followed by 0x00 or RSTn, so FF D9
	 *   after SOS is EOI in practice; only table segments between progressive
	 *   scans could theoretically contain the pair.
	 * - GIF: true when the first frame is missing or its data ends before the
	 *   block terminator.
	 * - Other formats: false. Their decoders reject truncated data themselves.
	 *
	 * Notes:
	 * - Reads the whole JPEG once in fixed windows; memory use is constant.
	 *
	 * @param ImageFormat $format Format detected from content.
	 * @param \Closure(int, int): string $read Reader.
	 * @return bool True when truncation is detected.
	 */
	public static function isTruncated(ImageFormat $format, \Closure $read): bool {
		return match ($format) {
			ImageFormat::Jpeg => self::jpegTruncated($read),
			ImageFormat::Gif => self::gifTruncated($read),
			default => false,
		};
	}


	/**
	 * Detect an ISOBMFF container whose brands identify HEIC.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $length Total length of the data in bytes.
	 * @return bool True when the leading ftyp box carries a HEIC brand.
	 */
	public static function isHeic(\Closure $read, int $length): bool {
		foreach (self::ftypBrands($read, $length) ?? [] as $brand) {
			if (\in_array($brand, self::HEIC_BRANDS, true)) {
				return true;
			}
		}

		return false;
	}


	// ----------------------------------------------------------------
	// Per-format scanners
	// ----------------------------------------------------------------

	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return array{orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool}
	 */
	private static function scanJpeg(\Closure $read): array {
		$result = ['orientation' => 1, 'multi_frame' => false, 'alpha' => false, 'icc_profile' => false, 'icc' => null, 'cmyk' => false];

		if ($read(0, 2) !== "\xFF\xD8") {
			return $result;
		}

		$offset = 2;
		$exifSeen = false;
		$chunks = [];
		$chunkCount = null;
		$chunkBytes = 0;
		$chunksBroken = false;

		while ($offset < self::JPEG_METADATA_BYTES) {
			$header = $read($offset, 4);

			if (\strlen($header) < 2 || $header[0] !== "\xFF") {
				break;
			}

			$marker = \ord($header[1]);

			// Fill byte before a marker.
			if ($marker === 0xFF) {
				$offset++;
				continue;
			}

			// Standalone markers carry no length field.
			if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
				$offset += 2;
				continue;
			}

			// Start of scan or end of image: no metadata segments follow.
			if ($marker === 0xDA || $marker === 0xD9 || \strlen($header) < 4) {
				break;
			}

			$length = (\ord($header[2]) << 8) | \ord($header[3]);

			if ($length < 2) {
				break;
			}

			if ($marker === 0xE1 && !$exifSeen && $offset < self::JPEG_SCAN_BYTES) {
				$payload = $read($offset + 4, $length - 2);

				if (\strncmp($payload, "Exif\x00\x00", 6) === 0) {
					// Only the first EXIF segment is authoritative.
					$exifSeen = true;
					$tiff = \substr($payload, 6);
					$result['orientation'] = self::tiffIfd0(
						static fn(int $at, int $size): string => (string)\substr($tiff, $at, $size)
					)['orientation'];
				}
			} elseif ($marker === 0xE2 && $read($offset + 4, 12) === "ICC_PROFILE\x00") {
				// ICC profiles may span several APP2 segments: sequence number, count, data.
				$result['icc_profile'] = true;
				$sequence = $read($offset + 16, 2);
				$size = $length - 16;

				if (
					\strlen($sequence) < 2
					|| $size < 0
					|| \ord($sequence[0]) === 0
					|| ($chunkCount !== null && \ord($sequence[1]) !== $chunkCount)
					|| isset($chunks[\ord($sequence[0])])
					|| $chunkBytes + $size > self::ICC_LIMIT
				) {
					$chunksBroken = true;
				} else {
					$chunkCount = \ord($sequence[1]);
					$chunks[\ord($sequence[0])] = $read($offset + 18, $size);
					$chunkBytes += $size;
				}
			} elseif ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
				// Start of frame: four components are CMYK or YCCK.
				$result['cmyk'] = $read($offset + 9, 1) === "\x04";
			}

			$offset += 2 + $length;
		}

		if (!$chunksBroken && $chunkCount !== null && \count($chunks) === $chunkCount && \max(\array_keys($chunks)) === $chunkCount) {
			\ksort($chunks);
			$result['icc'] = \implode('', $chunks);
		}

		return $result;
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return array{orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool}
	 */
	private static function scanPng(\Closure $read): array {
		$result = ['orientation' => 1, 'multi_frame' => false, 'alpha' => false, 'icc_profile' => false, 'icc' => null, 'cmyk' => false];
		$offset = 8;

		while (true) {
			$header = $read($offset, 8);

			if (\strlen($header) < 8) {
				break;
			}

			$length = \unpack('N', $header)[1];
			$type = \substr($header, 4, 4);

			if ($type === 'IDAT' || $type === 'IEND') {
				break;
			}

			if ($type === 'IHDR') {
				$ihdr = $read($offset + 8, 13);

				// Color types 4 (gray + alpha) and 6 (RGBA) carry an alpha channel.
				if (\strlen($ihdr) === 13 && \in_array(\ord($ihdr[9]), [4, 6], true)) {
					$result['alpha'] = true;
				}
			} elseif ($type === 'tRNS') {
				$result['alpha'] = true;
			} elseif ($type === 'iCCP') {
				// Profile name (1-79 bytes), NUL, compression method 0, zlib data.
				$result['icc_profile'] = true;

				if ($length <= self::ICC_LIMIT) {
					$payload = $read($offset + 8, $length);
					$nul = \strpos($payload, "\x00");

					if (\strlen($payload) === $length && $nul !== false && $nul >= 1 && $nul <= 79 && ($payload[$nul + 1] ?? '') === "\x00") {
						// max_length bounds the allocation only roughly (zlib works in
						// chunks), so the limit is enforced again on the result.
						$profile = @\gzuncompress(\substr($payload, $nul + 2), self::ICC_LIMIT);
						$result['icc'] = \is_string($profile) && $profile !== '' && \strlen($profile) <= self::ICC_LIMIT ? $profile : null;
					}
				}
			} elseif ($type === 'acTL') {
				$result['multi_frame'] = true;
			}

			$offset += 12 + $length;
		}

		return $result;
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return array{orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool}
	 */
	private static function scanGif(\Closure $read): array {
		$result = ['orientation' => 1, 'multi_frame' => false, 'alpha' => false, 'icc_profile' => false, 'icc' => null, 'cmyk' => false];
		$header = $read(0, 13);

		if (\strlen($header) < 13) {
			return $result;
		}

		$offset = 13;
		$flags = \ord($header[10]);

		if (($flags & 0x80) !== 0) {
			$offset += 3 * (1 << (($flags & 0x07) + 1));
		}

		$frames = 0;

		while (true) {
			$introducer = $read($offset, 1);

			if ($introducer === '' || $introducer === "\x3B") {
				break;
			}

			if ($introducer === "\x21") {
				$label = $read($offset + 1, 1);

				if ($label === "\xF9" && $frames === 0) {
					// Graphic Control Extension of the first frame: bit 0 = transparency.
					$control = $read($offset + 3, 1);

					if ($control !== '' && (\ord($control) & 0x01) !== 0) {
						$result['alpha'] = true;
					}
				} elseif ($label === "\xFF" && $read($offset + 2, 12) === "\x0BICCRGBG1012") {
					// Application extension: the profile is the concatenated sub-blocks.
					$result['icc_profile'] = true;
					$result['icc'] = self::gifSubBlockData($read, $offset + 14);
				}

				$offset = self::skipGifSubBlocks($read, $offset + 2);

				if ($offset < 0) {
					break;
				}

				continue;
			}

			if ($introducer !== "\x2C") {
				break;
			}

			$frames++;

			if ($frames > 1) {
				$result['multi_frame'] = true;
				break;
			}

			$descriptor = $read($offset + 1, 9);

			if (\strlen($descriptor) < 9) {
				break;
			}

			$packed = \ord($descriptor[8]);
			$offset += 10;

			if (($packed & 0x80) !== 0) {
				$offset += 3 * (1 << (($packed & 0x07) + 1));
			}

			// Skip the LZW minimum code size byte, then the image data.
			$offset = self::skipGifSubBlocks($read, $offset + 1);

			if ($offset < 0) {
				break;
			}
		}

		return $result;
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return array{orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool}
	 */
	private static function scanWebp(\Closure $read): array {
		$result = ['orientation' => 1, 'multi_frame' => false, 'alpha' => false, 'icc_profile' => false, 'icc' => null, 'cmyk' => false];
		$header = $read(0, 30);

		if (\strlen($header) < 21 || \strncmp($header, 'RIFF', 4) !== 0 || \substr($header, 8, 4) !== 'WEBP') {
			return $result;
		}

		$chunk = \substr($header, 12, 4);

		if ($chunk === 'VP8X') {
			$flags = \ord($header[20]);
			$result['icc_profile'] = ($flags & 0x20) !== 0;
			$result['alpha'] = ($flags & 0x10) !== 0;
			$result['multi_frame'] = ($flags & 0x02) !== 0;

			if ($result['icc_profile']) {
				$result['icc'] = self::webpIccProfile($read);
			}
		} elseif ($chunk === 'VP8L' && \strlen($header) >= 25) {
			// Lossless bitstream: bit 28 of the 32-bit field after the signature.
			$result['alpha'] = ((\unpack('V', $header, 21)[1] >> 28) & 0x01) === 1;
		}

		return $result;
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $length Total length of the data in bytes.
	 * @return array{orientation:int, multi_frame:bool|null, alpha:bool|null, icc_profile:bool|null}
	 */
	private static function scanHeif(\Closure $read, int $length): array {
		$heif = self::heif($read, $length);

		// EXIF orientation is not used for HEIF; container transforms are
		// reported by heif() and applied as part of the image definition.
		if ($heif === null) {
			$brands = self::ftypBrands($read, $length);
			$sequence = $brands !== null && \array_intersect($brands, self::SEQUENCE_BRANDS) !== [];

			return ['orientation' => 1, 'multi_frame' => $sequence ? true : null, 'alpha' => null, 'icc_profile' => null, 'icc' => null, 'cmyk' => false];
		}

		return ['orientation' => 1, 'multi_frame' => $heif['multi_frame'], 'alpha' => $heif['alpha'], 'icc_profile' => $heif['icc_profile'], 'icc' => $heif['icc'], 'cmyk' => false];
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return array{orientation:int, multi_frame:bool, alpha:bool, icc_profile:bool}
	 */
	private static function scanTiff(\Closure $read): array {
		$ifd0 = self::tiffIfd0($read);

		return [
			'orientation' => $ifd0['orientation'],
			'multi_frame' => $ifd0['next_ifd'],
			'alpha' => $ifd0['alpha'],
			'icc_profile' => $ifd0['icc_profile'],
			'icc' => $ifd0['icc'],
			'cmyk' => $ifd0['cmyk'],
		];
	}


	// ----------------------------------------------------------------
	// Integrity checks
	// ----------------------------------------------------------------

	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return bool True when no EOI follows the first SOS.
	 */
	private static function jpegTruncated(\Closure $read): bool {
		if ($read(0, 2) !== "\xFF\xD8") {
			return true;
		}

		// -- 1. Walk marker segments to the first SOS ---------------------
		$offset = 2;

		while (true) {
			$header = $read($offset, 4);

			if (\strlen($header) < 2 || $header[0] !== "\xFF") {
				return true;
			}

			$marker = \ord($header[1]);

			if ($marker === 0xFF) {
				$offset++;
				continue;
			}

			if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
				$offset += 2;
				continue;
			}

			if ($marker === 0xD9 || \strlen($header) < 4) {
				return true;
			}

			$length = (\ord($header[2]) << 8) | \ord($header[3]);

			if ($length < 2) {
				return true;
			}

			$offset += 2 + $length;

			if ($marker === 0xDA) {
				break;
			}
		}

		// -- 2. Search entropy-coded data for EOI in fixed windows ---------
		$carry = '';

		while (true) {
			$window = $read($offset, 65_536);

			if ($window === '') {
				return true;
			}

			if (\str_contains($carry . $window, "\xFF\xD9")) {
				return false;
			}

			$carry = \substr($window, -1);
			$offset += \strlen($window);
		}
	}


	/**
	 * @param \Closure(int, int): string $read Reader.
	 * @return bool True when the first frame is missing or incomplete.
	 */
	private static function gifTruncated(\Closure $read): bool {
		$header = $read(0, 13);

		if (\strlen($header) < 13) {
			return true;
		}

		$offset = 13;
		$flags = \ord($header[10]);

		if (($flags & 0x80) !== 0) {
			$offset += 3 * (1 << (($flags & 0x07) + 1));
		}

		while (true) {
			$introducer = $read($offset, 1);

			if ($introducer === "\x21") {
				$offset = self::skipGifSubBlocks($read, $offset + 2);

				if ($offset < 0) {
					return true;
				}

				continue;
			}

			if ($introducer !== "\x2C") {
				// Trailer, EOF, or garbage before any frame.
				return true;
			}

			$descriptor = $read($offset + 1, 9);

			if (\strlen($descriptor) < 9) {
				return true;
			}

			$packed = \ord($descriptor[8]);
			$offset += 10;

			if (($packed & 0x80) !== 0) {
				$offset += 3 * (1 << (($packed & 0x07) + 1));
			}

			return self::skipGifSubBlocks($read, $offset + 1) < 0;
		}
	}


	// ----------------------------------------------------------------
	// Shared structure readers
	// ----------------------------------------------------------------

	/**
	 * Read selected IFD0 facts from a TIFF structure (TIFF file or EXIF payload).
	 *
	 * @param \Closure(int, int): string $read Reader positioned so that offset 0 is the TIFF header.
	 * @return array{orientation:int, alpha:bool, icc_profile:bool, icc:string|null, cmyk:bool, next_ifd:bool} IFD0 facts.
	 */
	private static function tiffIfd0(\Closure $read): array {
		$result = ['orientation' => 1, 'alpha' => false, 'icc_profile' => false, 'icc' => null, 'cmyk' => false, 'next_ifd' => false];
		$profile = null;
		$header = $read(0, 8);

		if (\strlen($header) < 8) {
			return $result;
		}

		$byteOrder = \substr($header, 0, 2);

		if ($byteOrder === 'II') {
			$short = 'v';
			$long = 'V';
		} elseif ($byteOrder === 'MM') {
			$short = 'n';
			$long = 'N';
		} else {
			return $result;
		}

		if (\unpack($short, $header, 2)[1] !== 42) {
			return $result;
		}

		$ifdOffset = \unpack($long, $header, 4)[1];
		$countBytes = $read($ifdOffset, 2);

		if ($ifdOffset < 8 || \strlen($countBytes) < 2) {
			return $result;
		}

		$count = \min(\unpack($short, $countBytes)[1], self::TIFF_MAX_ENTRIES);
		$entries = $read($ifdOffset + 2, $count * 12 + 4);
		$available = \intdiv(\strlen($entries), 12);

		for ($i = 0; $i < $count && $i < $available; $i++) {
			$at = $i * 12;
			$tag = \unpack($short, $entries, $at)[1];
			$type = \unpack($short, $entries, $at + 2)[1];
			$valueCount = \unpack($long, $entries, $at + 4)[1];

			if ($tag === 0x0112) {
				// Orientation: a single SHORT stored left-aligned in the value field.
				$value = \unpack($short, $entries, $at + 8)[1];

				if ($type === 3 && $valueCount >= 1 && $value >= 1 && $value <= 8) {
					$result['orientation'] = $value;
				}
			} elseif ($tag === 0x0152) {
				// ExtraSamples: 1 = associated alpha, 2 = unassociated alpha.
				$value = \unpack($short, $entries, $at + 8)[1];
				$result['alpha'] = $type === 3 && $valueCount === 1 && ($value === 1 || $value === 2);
			} elseif ($tag === 0x8773) {
				// InterColorProfile: UNDEFINED bytes at a 32-bit offset.
				$result['icc_profile'] = true;

				if ($type === 7 && $valueCount > 4 && $valueCount <= self::ICC_LIMIT) {
					$profile = [\unpack($long, $entries, $at + 8)[1], $valueCount];
				}
			} elseif ($tag === 0x0106) {
				// PhotometricInterpretation 5: separated (CMYK).
				$result['cmyk'] = $type === 3 && \unpack($short, $entries, $at + 8)[1] === 5;
			}
		}

		if ($profile !== null) {
			$bytes = $read($profile[0], $profile[1]);
			$result['icc'] = \strlen($bytes) === $profile[1] ? $bytes : null;
		}

		if ($count === \unpack($short, $countBytes)[1] && \strlen($entries) === $count * 12 + 4) {
			$result['next_ifd'] = \unpack($long, $entries, $count * 12)[1] !== 0;
		}

		return $result;
	}


	/**
	 * Read one ISOBMFF box header.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $offset Box start.
	 * @param int $limit Exclusive end of the enclosing box or data.
	 * @return array{type:string, body:int, end:int}|null Box, or null when absent or malformed.
	 */
	private static function box(\Closure $read, int $offset, int $limit): ?array {
		$header = $read($offset, 16);

		if (\strlen($header) < 8 || $offset + 8 > $limit) {
			return null;
		}

		$size = \unpack('N', $header)[1];
		$body = $offset + 8;

		if ($size === 1) {
			if (\strlen($header) < 16) {
				return null;
			}

			$size = \unpack('J', $header, 8)[1];
			$body = $offset + 16;
		} elseif ($size === 0) {
			// Extends to the end of the enclosing box (or data).
			$size = $limit - $offset;
		}

		if ($size < $body - $offset || $size > $limit - $offset) {
			return null;
		}

		return ['type' => \substr($header, 4, 4), 'body' => $body, 'end' => $offset + $size];
	}


	/**
	 * List the child boxes of a container body.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $start Body start.
	 * @param int $end Body end.
	 * @return list<array{type:string, body:int, end:int}> Child boxes in order.
	 */
	private static function children(\Closure $read, int $start, int $end): array {
		$children = [];

		while ($start < $end && \count($children) < self::BOX_LIMIT) {
			$box = self::box($read, $start, $end);

			if ($box === null) {
				break;
			}

			$children[] = $box;
			$start = $box['end'];
		}

		return $children;
	}


	/**
	 * Parse an ipma box body into item ID => list of 1-based property indices.
	 *
	 * @param string $data ipma body including version and flags.
	 * @return array<int, list<int>> Associations.
	 */
	private static function ipma(string $data): array {
		$length = \strlen($data);

		if ($length < 8) {
			return [];
		}

		$version = \ord($data[0]);
		$wide = (\ord($data[3]) & 0x01) === 1;
		$count = \unpack('N', $data, 4)[1];
		$at = 8;
		$result = [];

		for ($i = 0; $i < $count && $i < self::BOX_LIMIT; $i++) {
			$idSize = $version < 1 ? 2 : 4;

			if ($at + $idSize + 1 > $length) {
				break;
			}

			$item = $version < 1 ? \unpack('n', $data, $at)[1] : \unpack('N', $data, $at)[1];
			$associationCount = \ord($data[$at + $idSize]);
			$at += $idSize + 1;
			$indices = [];

			for ($j = 0; $j < $associationCount; $j++) {
				if ($wide) {
					if ($at + 2 > $length) {
						break 2;
					}

					$indices[] = \unpack('n', $data, $at)[1] & 0x7FFF;
					$at += 2;
				} else {
					if ($at + 1 > $length) {
						break 2;
					}

					$indices[] = \ord($data[$at]) & 0x7F;
					$at++;
				}
			}

			$result[$item] = $indices;
		}

		return $result;
	}


	/**
	 * Parse an iref box body into auxiliary item ID => referenced item IDs ('auxl').
	 *
	 * @param string $data iref body including version and flags.
	 * @return array<int, list<int>> Auxiliary references.
	 */
	private static function auxiliaryReferences(string $data): array {
		$length = \strlen($data);

		if ($length < 4) {
			return [];
		}

		$idSize = \ord($data[0]) === 0 ? 2 : 4;
		$id = static fn(int $at): int => $idSize === 2 ? \unpack('n', $data, $at)[1] : \unpack('N', $data, $at)[1];
		$result = [];

		for ($at = 4, $boxes = 0; $at + 8 <= $length && $boxes < self::BOX_LIMIT; $boxes++) {
			$size = \unpack('N', $data, $at)[1];
			$end = $at + $size;

			if ($size < 8 + $idSize + 2 || $end > $length) {
				break;
			}

			$from = $id($at + 8);
			$count = \unpack('n', $data, $at + 8 + $idSize)[1];

			if (\substr($data, $at + 4, 4) === 'auxl') {
				for ($i = 0, $ref = $at + 10 + $idSize; $i < $count && $ref + $idSize <= $end; $i++, $ref += $idSize) {
					$result[$from][] = $id($ref);
				}
			}

			$at = $end;
		}

		return $result;
	}


	/**
	 * Resolve a clap box into an integer crop rectangle, as libheif does.
	 *
	 * Behavior:
	 * - left/right/top/bottom are rounded down from the exact rational edges;
	 *   width = right - left + 1, height likewise.
	 * - Rationals are reduced first; a clap whose denominators still exceed
	 *   CLAP_DENOMINATOR_LIMIT, whose clean size exceeds the coded size, or
	 *   whose edges fall outside the image is invalid. With these bounds every
	 *   intermediate product stays below 2^62, so the arithmetic is exact and
	 *   cannot overflow.
	 *
	 * @param list<int> $clap Eight unsigned 32-bit fields: width N/D, height N/D, horizOff N/D, vertOff N/D.
	 * @param int $width Coded width.
	 * @param int $height Coded height.
	 * @return array{0:int, 1:int, 2:int, 3:int}|null [x, y, width, height], or null when invalid.
	 */
	private static function cleanAperture(array $clap, int $width, int $height): ?array {
		$signed = static fn(int $value): int => $value >= 0x80000000 ? $value - 0x100000000 : $value;
		[$widthN, $widthD, $heightN, $heightD, $offsetXN, $offsetXD, $offsetYN, $offsetYD] = $clap;

		$x = self::apertureEdges($width, $widthN, $widthD, $signed($offsetXN), $offsetXD);
		$y = self::apertureEdges($height, $heightN, $heightD, $signed($offsetYN), $offsetYD);

		return ($x === null || $y === null) ? null : [$x[0], $y[0], $x[1], $y[1]];
	}


	/**
	 * Resolve one clean-aperture axis.
	 *
	 * edge = offset + (size - 1) / 2 -/+ (clean - 1) / 2, evaluated exactly over
	 * the common denominator 2 * offsetD * cleanD.
	 *
	 * @param int $size Coded size on this axis (1 to 2^32 - 1).
	 * @param int $cleanN Clean size numerator (unsigned 32-bit).
	 * @param int $cleanD Clean size denominator (unsigned 32-bit).
	 * @param int $offsetN Offset numerator (signed 32-bit).
	 * @param int $offsetD Offset denominator (unsigned 32-bit).
	 * @return array{0:int, 1:int}|null [first pixel, pixel count], or null when invalid.
	 */
	private static function apertureEdges(int $size, int $cleanN, int $cleanD, int $offsetN, int $offsetD): ?array {
		if ($cleanD === 0 || $offsetD === 0 || $cleanN === 0) {
			return null;
		}

		[$cleanN, $cleanD] = self::reduce($cleanN, $cleanD);
		[$offsetN, $offsetD] = self::reduce($offsetN, $offsetD);

		// Range checks before any product: denominators small, clean size <= coded size.
		if ($cleanD > self::CLAP_DENOMINATOR_LIMIT || $offsetD > self::CLAP_DENOMINATOR_LIMIT || $cleanN > $size * $cleanD) {
			return null;
		}

		// Bounds: offsetD * cleanD <= 2^28, size < 2^32, |offsetN| <= 2^31,
		// cleanN <= size * cleanD < 2^46. Every term below is < 2^61.
		$denominator = 2 * $offsetD * $cleanD;
		$center = 2 * $offsetN * $cleanD + ($size - 1) * $offsetD * $cleanD;
		$half = ($cleanN - $cleanD) * $offsetD;
		$low = self::floorDiv($center - $half, $denominator);
		$high = self::floorDiv($center + $half, $denominator);

		return ($low < 0 || $high >= $size || $high < $low) ? null : [$low, $high - $low + 1];
	}


	/**
	 * Reduce a fraction by its greatest common divisor.
	 *
	 * @param int $numerator Numerator (may be negative).
	 * @param int $denominator Positive denominator.
	 * @return array{0:int, 1:int} Reduced numerator and denominator.
	 */
	private static function reduce(int $numerator, int $denominator): array {
		[$a, $b] = [\abs($numerator), $denominator];

		while ($b !== 0) {
			[$a, $b] = [$b, $a % $b];
		}

		return $a > 1 ? [\intdiv($numerator, $a), \intdiv($denominator, $a)] : [$numerator, $denominator];
	}


	/**
	 * Integer division rounded toward negative infinity.
	 *
	 * @param int $numerator Numerator.
	 * @param int $denominator Positive denominator.
	 * @return int Floor of the quotient.
	 */
	private static function floorDiv(int $numerator, int $denominator): int {
		$quotient = \intdiv($numerator, $denominator);

		return ($numerator % $denominator !== 0 && $numerator < 0) ? $quotient - 1 : $quotient;
	}


	/**
	 * Read the ICCP chunk of an extended (VP8X) WebP file.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @return string|null Profile bytes, or null when absent, truncated, or above ICC_LIMIT.
	 */
	private static function webpIccProfile(\Closure $read): ?string {
		for ($offset = 12, $chunks = 0; $chunks < self::BOX_LIMIT; $chunks++) {
			$header = $read($offset, 8);

			if (\strlen($header) < 8) {
				return null;
			}

			$size = \unpack('V', $header, 4)[1];

			if (\substr($header, 0, 4) === 'ICCP') {
				if ($size > self::ICC_LIMIT) {
					return null;
				}

				$bytes = $read($offset + 8, $size);

				return \strlen($bytes) === $size ? $bytes : null;
			}

			$offset += 8 + $size + ($size & 1);
		}

		return null;
	}


	/**
	 * Concatenate a chain of GIF data sub-blocks, bounded by ICC_LIMIT.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $offset Offset of the first sub-block size byte.
	 * @return string|null Data, or null when truncated, empty, or above ICC_LIMIT.
	 */
	private static function gifSubBlockData(\Closure $read, int $offset): ?string {
		$data = '';

		while (true) {
			$size = $read($offset, 1);

			if ($size === '') {
				return null;
			}

			if ($size === "\x00") {
				return $data !== '' ? $data : null;
			}

			$block = $read($offset + 1, \ord($size));

			if (\strlen($block) !== \ord($size) || \strlen($data) + \strlen($block) > self::ICC_LIMIT) {
				return null;
			}

			$data .= $block;
			$offset += 1 + \ord($size);
		}
	}


	/**
	 * Skip a chain of GIF data sub-blocks.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $offset Offset of the first sub-block size byte.
	 * @return int Offset after the block terminator, or -1 when data ends first.
	 */
	private static function skipGifSubBlocks(\Closure $read, int $offset): int {
		while (true) {
			$size = $read($offset, 1);

			if ($size === '') {
				return -1;
			}

			if ($size === "\x00") {
				return $offset + 1;
			}

			$offset += 1 + \ord($size);
		}
	}


	/**
	 * Return major and compatible brands of the leading ISOBMFF ftyp box.
	 *
	 * Behavior:
	 * - Reads the whole box, bounded by the real data length and FTYP_LIMIT.
	 * - Fails closed: a missing, truncated, malformed, or oversized ftyp box
	 *   yields null, never a partial brand list.
	 *
	 * @param \Closure(int, int): string $read Reader.
	 * @param int $length Total length of the data in bytes.
	 * @return list<string>|null Major brand followed by compatible brands, or null.
	 */
	private static function ftypBrands(\Closure $read, int $length): ?array {
		$box = self::box($read, 0, $length);

		if ($box === null || $box['type'] !== 'ftyp') {
			return null;
		}

		$size = $box['end'] - $box['body'];

		// major_brand (4) + minor_version (4) + compatible brands (4 each).
		if ($size < 8 || $size % 4 !== 0 || $box['end'] > self::FTYP_LIMIT) {
			return null;
		}

		$body = $read($box['body'], $size);

		if (\strlen($body) !== $size) {
			return null;
		}

		$brands = [\substr($body, 0, 4)];

		for ($at = 8; $at < $size; $at += 4) {
			$brands[] = \substr($body, $at, 4);
		}

		return $brands;
	}


}

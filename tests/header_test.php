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

/*
 * Regression checks for Util\ImageHeader: EXIF orientation, multi-frame
 * detection, alpha and ICC flags, truncation detection, and malformed input.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Tests\Support\ImageFixtures as F;
use CitOmni\Image\Util\ImageHeader;

function scan(ImageFormat $format, string $data): array {
	return ImageHeader::scan($format, static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length), \strlen($data));
}

function truncated(ImageFormat $format, string $data): bool {
	return ImageHeader::isTruncated($format, static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length));
}

// -- JPEG EXIF orientation ----------------------------------------------------

for ($orientation = 1; $orientation <= 8; $orientation++) {
	check(scan(ImageFormat::Jpeg, F::jpeg($orientation))['orientation'] === $orientation, "jpeg LE orientation $orientation");
	check(scan(ImageFormat::Jpeg, F::jpeg($orientation, true))['orientation'] === $orientation, "jpeg BE orientation $orientation");
}

$plain = F::jpeg();
$splice = static fn(string $segments): string => \substr($plain, 0, 2) . $segments . \substr($plain, 2);

check(scan(ImageFormat::Jpeg, $plain)['orientation'] === 1, 'no EXIF -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::exifSegment(6) . F::exifSegment(3)))['orientation'] === 6, 'first EXIF segment wins');
check(scan(ImageFormat::Jpeg, $splice(F::exifSegment(9)))['orientation'] === 1, 'out-of-range value -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::exifSegment(0)))['orientation'] === 1, 'zero value -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::exifSegment(6, false, 4)))['orientation'] === 1, 'wrong type (LONG) -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::segment(0xE1, "Exif\x00\x00II*\x00\x08")))['orientation'] === 1, 'truncated TIFF header -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::segment(0xE1, "Exif\x00\x00II*\x00" . \pack('V', 0xFFFFFF00))))['orientation'] === 1, 'IFD offset out of range -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::segment(0xE1, "Exif\x00\x00II*\x00" . \pack('V', 8) . \pack('v', 500))))['orientation'] === 1, 'entry count beyond data -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::segment(0xE1, "Exif\x00\x00XX*\x00" . \pack('V', 8))))['orientation'] === 1, 'bad byte order -> 1');
check(scan(ImageFormat::Jpeg, $splice(F::segment(0xE1, 'http://ns.adobe.com/xap/1.0/' . "\x00<x/>") . F::exifSegment(8)))['orientation'] === 8, 'XMP APP1 before EXIF is skipped');
check(scan(ImageFormat::Jpeg, $splice("\xFF\xE1\x00\x01"))['orientation'] === 1, 'segment length < 2 stops scan');
check(scan(ImageFormat::Jpeg, 'not a jpeg')['orientation'] === 1, 'no SOI -> 1');

// Segments starting beyond the scan window are ignored, identically for any reader.
$padding = F::segment(0xE3, \str_repeat("\x00", 65_000));
$deep = $splice(\str_repeat($padding, 5) . F::exifSegment(6));
check(scan(ImageFormat::Jpeg, $deep)['orientation'] === 1, 'EXIF beyond JPEG_SCAN_BYTES ignored');
$shallow = $splice(\str_repeat($padding, 3) . F::exifSegment(6));
check(scan(ImageFormat::Jpeg, $shallow)['orientation'] === 6, 'EXIF within JPEG_SCAN_BYTES honored');

// -- JPEG ICC / alpha / frames -----------------------------------------------------

$meta = scan(ImageFormat::Jpeg, F::jpeg(null, false, true));
check($meta['icc_profile'] === true && $meta['alpha'] === false && $meta['multi_frame'] === false, 'jpeg ICC detected, no alpha, single frame');
check(scan(ImageFormat::Jpeg, $plain)['icc_profile'] === false, 'jpeg without ICC');

// -- PNG ----------------------------------------------------------------------------

check(scan(ImageFormat::Png, F::alphaPng())['alpha'] === true, 'png RGBA alpha');
check(scan(ImageFormat::Png, F::encode(F::quadrants(), 'png'))['alpha'] === false, 'png RGB no alpha');
check(scan(ImageFormat::Png, F::palettePng())['alpha'] === true, 'png tRNS alpha');
check(scan(ImageFormat::Png, F::apng())['multi_frame'] === true, 'APNG detected');
check(scan(ImageFormat::Png, F::alphaPng())['multi_frame'] === false, 'plain png single frame');
check(scan(ImageFormat::Png, F::iccPng())['icc_profile'] === true, 'png iCCP detected');

// -- GIF ----------------------------------------------------------------------------

check(scan(ImageFormat::Gif, F::animatedGif())['multi_frame'] === true, 'animated gif detected');
check(scan(ImageFormat::Gif, F::transparentGif())['multi_frame'] === false, 'single-frame gif');
check(scan(ImageFormat::Gif, F::transparentGif())['alpha'] === true, 'gif transparency');
check(scan(ImageFormat::Gif, F::encode(F::quadrants(20, 20), 'gif'))['alpha'] === false, 'gif without transparency');
check(scan(ImageFormat::Gif, 'GIF8')['multi_frame'] === false, 'short gif');

// -- WebP / AVIF / TIFF / BMP / HEIC ----------------------------------------------------

$meta = scan(ImageFormat::Webp, F::webpVp8x(0x02 | 0x10 | 0x20));
check($meta['multi_frame'] === true && $meta['alpha'] === true && $meta['icc_profile'] === true, 'webp VP8X flags');
$meta = scan(ImageFormat::Webp, F::webpVp8x(0));
check($meta['multi_frame'] === false && $meta['alpha'] === false && $meta['icc_profile'] === false, 'webp VP8X no flags');
check(scan(ImageFormat::Webp, F::encode(F::quadrants(), 'webp'))['alpha'] === false, 'lossy webp no alpha');

$lossless = \imagecreatetruecolor(8, 8);
\imagealphablending($lossless, false);
\imagesavealpha($lossless, true);
\imagefill($lossless, 0, 0, \imagecolorallocatealpha($lossless, 10, 20, 30, 100));
\ob_start();
\imagewebp($lossless, null, \IMG_WEBP_LOSSLESS);
$losslessData = (string)\ob_get_clean();

if (\substr($losslessData, 12, 4) === 'VP8L') {
	check(scan(ImageFormat::Webp, $losslessData)['alpha'] === true, 'lossless webp alpha bit');
} elseif (\substr($losslessData, 12, 4) === 'VP8X') {
	check(scan(ImageFormat::Webp, $losslessData)['alpha'] === true, 'extended lossless webp alpha flag');
}

check(scan(ImageFormat::Avif, F::ftyp('avis', ['avif', 'mif1']))['multi_frame'] === true, 'avif sequence brand without meta');
check(scan(ImageFormat::Avif, F::ftyp('avif', ['mif1', 'miaf']))['multi_frame'] === null, 'unparsable avif: frames unknown');
check(scan(ImageFormat::Avif, F::ftyp('avif', []))['alpha'] === null, 'unparsable avif: alpha unknown');
check(scan(ImageFormat::Avif, F::heifContainer('avif', 40, 30, [], ['mif1', 'avif']))['multi_frame'] === false, 'avif still');
check(scan(ImageFormat::Avif, F::heifContainer('avis', 40, 30, [], ['mif1', 'avif']))['multi_frame'] === true, 'avif sequence');

// -- HEIF container geometry --------------------------------------------------------

function heif(string $data): ?array {
	return ImageHeader::heif(static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length), \strlen($data));
}

$plainHeif = heif(F::heifContainer('heic', 400, 300));
check([$plainHeif['width'], $plainHeif['height'], $plainHeif['region'], $plainHeif['orientation']] === [400, 300, [0, 0, 400, 300], 1], 'plain heif');

foreach ([0 => 1, 1 => 8, 2 => 3, 3 => 6] as $angle => $orientation) {
	check(heif(F::heifContainer('heic', 40, 30, [['irot', $angle]]))['orientation'] === $orientation, "irot $angle (counter-clockwise) -> orientation $orientation");
}

// imir semantics as decoded by libheif (verified against ImageMagick in imagick_test.php).
check(heif(F::heifContainer('heic', 40, 30, [['imir', 0]]))['orientation'] === 4, 'imir mode 0 flips top-bottom');
check(heif(F::heifContainer('heic', 40, 30, [['imir', 1]]))['orientation'] === 2, 'imir mode 1 flips left-right');
check(heif(F::heifContainer('heic', 40, 30, [['irot', 1], ['imir', 1]]))['orientation'] === 7, 'irot then imir');
check(heif(F::heifContainer('heic', 40, 30, [['imir', 1], ['irot', 1]]))['orientation'] === 7, 'transform order is fixed by the specification, not the listing');

// Clean aperture, libheif rounding.
check(heif(F::heifContainer('heic', 64, 64, [['clap', [64, 1, 48, 1, 0, 2, 0xFFFFFFF0, 2]]]))['region'] === [0, 0, 64, 48], 'clap with negative vertical offset (libheif sample)');
check(heif(F::heifContainer('heic', 40, 30, [['clap', [30, 1, 20, 1, 0, 1, 0, 1]]]))['region'] === [5, 5, 30, 20], 'centered clap');
check(heif(F::heifContainer('heic', 41, 31, [['clap', [30, 1, 20, 1, 0, 1, 0, 1]]]))['region'] === [5, 5, 30, 20], 'centered clap, odd coded size rounds down');
check(heif(F::heifContainer('heic', 66, 64, [['clap', [65, 1, 49, 1, 0xFFFFFFFF, 2, 0xFFFFFFF1, 2]]]))['region'] === [0, 0, 65, 49], 'encoder padding clap (ImageMagick HEIC output shape)');
check(heif(F::heifContainer('heic', 40, 30, [['clap', [50, 1, 20, 1, 0, 1, 0, 1]]])) === null, 'clap wider than the image is invalid');
check(heif(F::heifContainer('heic', 40, 30, [['clap', [30, 0, 20, 1, 0, 1, 0, 1]]])) === null, 'clap with zero denominator is invalid');

check(heif(F::heifContainer('avif', 40, 30, [['colr', 'prof']], ['mif1', 'avif']))['icc_profile'] === true, 'heif colr prof is an ICC profile');
check(heif(F::heifContainer('heic', 40, 30, [['colr', 'nclx']]))['icc_profile'] === false, 'heif colr nclx is not an ICC profile');

// Alpha only counts when an alpha auxiliary item references the primary item (iref auxl).
check(heif(F::heifContainer('heic', 40, 30, [], ['mif1'], 'linked'))['alpha'] === true, 'alpha item linked to the primary item');
check(heif(F::heifContainer('heic', 40, 30, [], ['mif1'], 'unlinked'))['alpha'] === false, 'alpha item of another item does not count');
check(heif(F::heifContainer('heic', 40, 30, [['auxC', 'urn:mpeg:hevc:2015:auxid:1']]))['alpha'] === false, 'alpha auxC without an auxl reference does not count');
check(heif(F::heifContainer('heic', 40, 30))['alpha'] === false, 'no alpha item');

// Sequence brands: every HEIF image-sequence brand marks the file multi-frame, with or without a meta box.
foreach (['avis', 'msf1', 'hevc', 'hevx', 'hevm', 'hevs'] as $brand) {
	check(heif(F::heifContainer('heic', 40, 30, [], ['mif1', $brand]))['multi_frame'] === true, "sequence brand $brand -> multi-frame");
	check(scan(ImageFormat::Heic, F::ftyp('heic', ['mif1', $brand]))['multi_frame'] === true, "sequence brand $brand -> multi-frame without meta box");
}

foreach (['heic', 'heix', 'heim', 'heis', 'avif', 'mif1', 'miaf'] as $brand) {
	check(heif(F::heifContainer('heic', 40, 30, [], ['mif1', $brand]))['multi_frame'] === false, "still brand $brand -> single frame");
}

// -- HEIF hardening: untrusted sizes and rationals -----------------------------------

// Rationals at the 32-bit limits used to overflow into floats (TypeError). They are invalid, not fatal.
check(heif(F::heifContainer('heic', 64, 64, [['clap', [64, 0xFFFFFFFF, 48, 0xFFFFFFFF, 0, 0xFFFFFFFF, 0, 0xFFFFFFFF]]])) === null, 'clap with 32-bit denominators rejected');
check(heif(F::heifContainer('heic', 0xFFFFFFFF, 0xFFFFFFFF, [['clap', [0xFFFFFFFF, 1, 0xFFFFFFFF, 1, 0x7FFFFFFF, 1, 0x80000000, 1]]])) === null, 'clap with extreme offsets rejected');
check(heif(F::heifContainer('heic', 64, 64, [['clap', [128, 2, 96, 2, 0, 4, -32 & 0xFFFFFFFF, 4]]]))['region'] === [0, 0, 64, 48], 'unreduced rationals are reduced first');
check(heif(F::heifContainer('heic', 64, 64, [['clap', [64, 16_384, 48, 1, 0, 1, 0, 1]]])) !== null, 'denominator at the limit accepted');
check(heif(F::heifContainer('heic', 64, 64, [['clap', [64, 16_385, 48, 1, 0, 1, 0, 1]]])) === null, 'denominator above the limit rejected');

\mt_srand(20261004);
$failures = 0;

for ($i = 0; $i < 2_000; $i++) {
	$fields = [];

	for ($k = 0; $k < 8; $k++) {
		$fields[] = \mt_rand(0, 0xFFFFFFFF);
	}

	try {
		heif(F::heifContainer('heic', \mt_rand(1, 0xFFFFFFFF), \mt_rand(1, 0xFFFFFFFF), [['clap', $fields]]));
	} catch (\Throwable) {
		$failures++;
	}
}

check($failures === 0, '2000 random clap/ispe combinations parse without throwing');

// Declared box sizes beyond the data: rejected, and the reader is never asked for more than exists.
$meta = "\x00\x00\x00\x00" . F::isoBox('pitm', "\x00\x00\x00\x00\x00\x01") . \pack('N', 0xFFFFFF00) . 'iprp' . \pack('N', 0xFFFFFF00 - 8) . 'ipma';
$lying = F::isoBox('ftyp', "heic\x00\x00\x00\x00heicmif1") . \pack('N', 8 + \strlen($meta) + 0xFFFFFF00) . 'meta' . $meta;
$largest = 0;
$counting = static function (int $offset, int $length) use ($lying, &$largest): string {
	$largest = \max($largest, $length);
	return (string)\substr($lying, $offset, $length);
};
check(ImageHeader::heif($counting, \strlen($lying)) === null, 'boxes larger than the data are rejected');
check($largest <= 64, "no read request beyond the data (largest: $largest bytes)");

// Honest box sizes, but an oversized ipma body: capped, not read.
$ipma = F::isoBox('ipma', "\x00\x00\x00\x00" . \pack('N', 1) . \str_repeat("\x00", 1_048_600));
$bloated = F::isoBox('ftyp', "heic\x00\x00\x00\x00heicmif1")
	. F::isoBox('meta', "\x00\x00\x00\x00" . F::isoBox('pitm', "\x00\x00\x00\x00\x00\x01") . F::isoBox('iprp', F::isoBox('ipco', F::isoBox('ispe', "\x00\x00\x00\x00" . \pack('NN', 40, 30))) . $ipma));
$largest = 0;
$capped = static function (int $offset, int $length) use ($bloated, &$largest): string {
	$largest = \max($largest, $length);
	return (string)\substr($bloated, $offset, $length);
};
check(ImageHeader::heif($capped, \strlen($bloated)) === null && $largest < 1_048_576, 'ipma above the metadata limit rejected without reading it');

$full = F::heifContainer('heic', 40, 30, [['irot', 1]]);
check(heif(\substr($full, 0, \strlen($full) - 6)) === null, 'truncated meta box -> null');
check(heif(F::ftyp('heic', ['mif1'])) === null, 'no meta box -> null');
check(heif('') === null, 'empty -> null');

$meta = scan(ImageFormat::Tiff, F::tiffFile(40, 30, 6));
check($meta['orientation'] === 6 && $meta['multi_frame'] === false, 'tiff orientation, single page');
check(scan(ImageFormat::Tiff, F::tiff([[0x0112, 3, 1, 3]], true, 999))['multi_frame'] === true, 'tiff next IFD -> multi frame');
check(scan(ImageFormat::Tiff, F::tiff([[0x0152, 3, 1, 2]]))['alpha'] === true, 'tiff unassociated alpha');

check(scan(ImageFormat::Bmp, F::encode(F::quadrants(), 'bmp'))['alpha'] === null, 'bmp alpha unknown');

function isHeic(string $data): bool {
	return ImageHeader::isHeic(static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length), \strlen($data));
}

check(isHeic(F::ftyp('heic', ['mif1', 'heic'])), 'heic brand');
check(isHeic(F::ftyp('mif1', ['heic'])), 'heic compatible brand');
check(!isHeic(F::ftyp('avif', ['mif1', 'miaf'])), 'avif is not heic');
check(!isHeic(F::ftyp('mif1', ['miaf'])), 'bare mif1 is not heic');
check(!isHeic('short'), 'short data is not heic');

// The whole ftyp box is read: brands far beyond the first 64 bytes count.
$fillers = \array_fill(0, 20, 'miaf');
check(isHeic(F::ftyp('mif1', [...$fillers, 'heic'])), 'heic brand after 20 other brands');

foreach (['avis', 'msf1', 'hevc', 'hevx', 'hevm', 'hevs'] as $brand) {
	$late = F::heifContainer('heic', 40, 30, [], ['mif1', ...$fillers, $brand]);
	check(\strpos($late, $brand, 16) > 64 && heif($late)['multi_frame'] === true, "sequence brand $brand after 20 other brands");
	check(scan(ImageFormat::Heic, F::ftyp('heic', ['mif1', ...$fillers, $brand]))['multi_frame'] === true, "sequence brand $brand after 20 brands, no meta box");
}

// Fail closed: an ftyp box that cannot be read completely is never "no sequence".
$oversized = F::heifContainer('heic', 40, 30, [], ['mif1', ...\array_fill(0, 1100, 'miaf'), 'hevc']);
check(heif($oversized) === null, 'ftyp above the limit makes the container unparsable');
check(scan(ImageFormat::Heic, F::ftyp('heic', ['mif1', ...\array_fill(0, 1100, 'miaf'), 'hevc']))['multi_frame'] === null, 'ftyp above the limit: frames unknown, not false');
check(!isHeic(F::ftyp('mif1', [...\array_fill(0, 1100, 'miaf'), 'heic'])), 'ftyp above the limit is not recognized as heic');
$truncatedFtyp = \substr(F::ftyp('heic', ['mif1', ...$fillers, 'hevc']), 0, 60);
check(scan(ImageFormat::Heic, $truncatedFtyp)['multi_frame'] === null, 'truncated ftyp: frames unknown, not false');
$misaligned = \pack('N', 8 + 10) . 'ftyp' . 'heic' . "\x00\x00\x00\x00" . 'mi';
check(!isHeic($misaligned), 'ftyp with a partial brand is malformed');

// -- Truncation -------------------------------------------------------------------------

check(!truncated(ImageFormat::Jpeg, $plain), 'complete jpeg');
check(!truncated(ImageFormat::Jpeg, F::jpeg(6, false, true)), 'complete jpeg with APP segments');
check(!truncated(ImageFormat::Jpeg, $plain . "trailing data"), 'trailer after EOI accepted');
check(truncated(ImageFormat::Jpeg, \substr($plain, 0, \intdiv(\strlen($plain), 2))), 'half jpeg truncated');
check(truncated(ImageFormat::Jpeg, \substr($plain, 0, -2)), 'jpeg without EOI truncated');
check(truncated(ImageFormat::Jpeg, \substr($plain, 0, 300)), 'jpeg cut in headers truncated');

$gif = F::transparentGif();
check(!truncated(ImageFormat::Gif, $gif), 'complete gif');
check(!truncated(ImageFormat::Gif, F::animatedGif()), 'complete animated gif');
check(truncated(ImageFormat::Gif, \substr($gif, 0, \intdiv(\strlen($gif), 2))), 'half gif truncated');
check(truncated(ImageFormat::Gif, \substr($gif, 0, 13)), 'gif without frames truncated');

check(!truncated(ImageFormat::Png, \substr(F::alphaPng(), 0, 40)), 'png truncation left to the decoder');

done('header_test');

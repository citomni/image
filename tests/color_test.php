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
 * Color management checks: ICC classification, embedded-profile extraction
 * for every container, the color policy on a runtime without color
 * management (GD), and real conversions on Imagick checked against
 * LittleCMS reference values. The Imagick part skips without ext-imagick or
 * without verified color management.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Color\IccProfile;
use CitOmni\Image\Color\Profiles;
use CitOmni\Image\Enum\ColorSpace;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageInputException;
use CitOmni\Image\Service\Image;
use CitOmni\Image\Tests\Support\ColorIgnoringBackend;
use CitOmni\Image\Tests\Support\CountingBackend;
use CitOmni\Image\Tests\Support\IccFixtures as Icc;
use CitOmni\Image\Tests\Support\ImageFixtures as F;
use CitOmni\Image\Util\ImageHeader;
use CitOmni\Image\Util\ProbeSamples;

$srgb = Profiles::srgb();
$p3 = Profiles::displayP3();

// -- 1. Classification ------------------------------------------------------------------

$known = [
	'shipped sRGB' => [$srgb, ColorSpace::Srgb],
	'shipped Display P3' => [$p3, ColorSpace::DisplayP3],
	'Adobe RGB compatible' => [Icc::adobeRgb(), ColorSpace::AdobeRgb],
	'sGrey (sRGB curve)' => [Icc::sGrey(), ColorSpace::Srgb],
	'ProPhoto' => [Icc::proPhoto(), ColorSpace::Rgb],
	'Rec2020' => [Icc::rec2020(), ColorSpace::Rgb],
	'CMYK print profile' => [Icc::cmyk(), ColorSpace::Cmyk],
	'sRGB colorants, linear curve' => [Icc::withCurve($srgb, Icc::gammaCurve(1.0)), ColorSpace::Rgb],
	'sRGB colorants, gamma 2.2' => [Icc::withCurve($srgb, Icc::gammaCurve(2.2)), ColorSpace::Rgb],
	'P3 colorants, gamma 2.2' => [Icc::withCurve($p3, Icc::gammaCurve(2.2)), ColorSpace::Rgb],
	'Adobe colorants, sRGB curve' => [Icc::withCurve(Icc::adobeRgb(), Icc::srgbCurve()), ColorSpace::Rgb],
	'sRGB colorants, sRGB parametric curve' => [Icc::withCurve($srgb, Icc::srgbCurve()), ColorSpace::Srgb],
	'Adobe colorants, gamma 563/256' => [Icc::withCurve(Icc::adobeRgb(), Icc::gammaCurve(563 / 256)), ColorSpace::AdobeRgb],
	'gray, gamma 1.8' => [Icc::withCurve(Icc::sGrey(), Icc::gammaCurve(1.8)), ColorSpace::Gray],
	'Lab color space' => [Icc::withSpace($srgb, 'Lab '), ColorSpace::Other],
	'sRGB with an unrelated extra tag' => [Icc::withTag($srgb, 'zzzz', "text\x00\x00\x00\x00x"), ColorSpace::Srgb],
	'sRGB matrix plus A2B0 LUT' => [Icc::withTag($srgb, 'A2B0', Icc::lutTag()), ColorSpace::Rgb],
	'sRGB matrix plus D2B0 transform' => [Icc::withTag($srgb, 'D2B0', Icc::lutTag()), ColorSpace::Rgb],
	'P3 matrix plus A2B1 LUT' => [Icc::withTag($p3, 'A2B1', Icc::lutTag()), ColorSpace::Rgb],
	'sGrey plus A2B0 LUT' => [Icc::withTag(Icc::sGrey(), 'A2B0', Icc::lutTag()), ColorSpace::Gray],
];

foreach ($known as $label => [$profile, $expected]) {
	check(IccProfile::classify($profile) === $expected, "classify $label as {$expected->value}");
}

check(IccProfile::dataSpace($srgb) === 'rgb' && IccProfile::dataSpace(Icc::sGrey()) === 'gray' && IccProfile::dataSpace(Icc::cmyk()) === 'cmyk', 'data color spaces');
check(IccProfile::dataSpace(Icc::withSpace($srgb, 'Lab ')) === null, 'Lab has no supported data space');

$malformed = [
	'empty' => '',
	'truncated' => \substr($srgb, 0, 200),
	'no acsp signature' => \substr_replace($srgb, 'xxxx', 36, 4),
	'declared size beyond data' => \substr_replace($srgb, \pack('N', 99_999), 0, 4),
	'huge tag count' => \substr_replace($srgb, \pack('N', 0xFFFFFFFF), 128, 4),
	'tag offset beyond data' => \substr_replace($srgb, \pack('N', 0x7FFFFFF0), 136, 4),
	'duplicate rTRC (second one linear)' => Icc::withTag($srgb, 'rTRC', Icc::gammaCurve(1.0)),
	'duplicate A2B0' => Icc::withTag(Icc::withTag($srgb, 'A2B0', Icc::lutTag()), 'A2B0', Icc::lutTag()),
	'tag data inside the tag table' => \substr_replace($srgb, \pack('N', 140), 136, 4),
	'tag data inside the header' => \substr_replace($srgb, \pack('N', 64), 136, 4),
];

foreach ($malformed as $label => $profile) {
	check(IccProfile::classify($profile) === ColorSpace::Other && IccProfile::dataSpace($profile) === null, "malformed profile ($label) is Other");
}

\mt_srand(31_337);
$failures = 0;
$srgbMisclassified = 0;

for ($i = 0; $i < 2_000; $i++) {
	$mutated = $srgb;

	for ($k = 0; $k < 4; $k++) {
		$mutated[\mt_rand(0, \strlen($mutated) - 1)] = \chr(\mt_rand(0, 255));
	}

	try {
		IccProfile::classify($mutated);
	} catch (\Throwable) {
		$failures++;
	}
}

check($failures === 0, '2000 randomly corrupted profiles classify without throwing');

// -- 2. Embedded profile extraction ----------------------------------------------------------

function scanned(ImageFormat $format, string $data): array {
	return ImageHeader::scan($format, static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length), \strlen($data));
}

function heifOf(string $data): ?array {
	return ImageHeader::heif(static fn(int $offset, int $length): string => (string)\substr($data, $offset, $length), \strlen($data));
}

/** JPEG with ICC chunks spliced after SOI: list of [sequence, count, bytes]. */
function jpegWithChunks(array $chunks): string {
	$jpeg = F::encode(F::quadrants(20, 10), 'jpeg');
	$segments = '';

	foreach ($chunks as [$sequence, $count, $bytes]) {
		$segments .= F::segment(0xE2, "ICC_PROFILE\x00" . \chr($sequence) . \chr($count) . $bytes);
	}

	return \substr($jpeg, 0, 2) . $segments . \substr($jpeg, 2);
}

$parts = \str_split($p3, 170);
check(scanned(ImageFormat::Jpeg, jpegWithChunks([[1, 1, $p3]]))['icc'] === $p3, 'jpeg: single APP2 profile');
check(scanned(ImageFormat::Jpeg, jpegWithChunks([[2, 3, $parts[1]], [3, 3, $parts[2]], [1, 3, $parts[0]]]))['icc'] === $p3, 'jpeg: profile split over three segments, out of order');

foreach ([
	'missing chunk' => [[1, 3, $parts[0]], [3, 3, $parts[2]]],
	'duplicate sequence' => [[1, 2, $parts[0]], [1, 2, $parts[1]]],
	'count mismatch' => [[1, 2, $parts[0]], [2, 3, $parts[1]]],
	'sequence zero' => [[0, 1, $p3]],
] as $label => $chunks) {
	$meta = scanned(ImageFormat::Jpeg, jpegWithChunks($chunks));
	check($meta['icc_profile'] === true && $meta['icc'] === null, "jpeg: $label -> present but unreadable");
}

$sof = static fn(int $components): string => "\xFF\xD8" . F::segment(0xC0, "\x08\x00\x0A\x00\x14" . \chr($components) . \str_repeat("\x11\x11\x00", $components)) . "\xFF\xD9";
check(scanned(ImageFormat::Jpeg, $sof(4))['cmyk'] === true && scanned(ImageFormat::Jpeg, $sof(3))['cmyk'] === false, 'jpeg: four components are CMYK');

$png = F::encode(F::quadrants(20, 10), 'png');
$withChunk = static fn(string $chunk): string => \substr($png, 0, 33) . $chunk . \substr($png, 33);
check(scanned(ImageFormat::Png, $withChunk(F::pngChunk('iCCP', "Display P3\x00\x00" . \gzcompress($p3))))['icc'] === $p3, 'png: iCCP profile');
check(scanned(ImageFormat::Png, $withChunk(F::pngChunk('iCCP', "P3\x00\x01" . \gzcompress($p3))))['icc'] === null, 'png: unknown compression method');
check(scanned(ImageFormat::Png, $withChunk(F::pngChunk('iCCP', \str_repeat('n', 90) . \gzcompress($p3))))['icc'] === null, 'png: name without terminator');
$bomb = scanned(ImageFormat::Png, $withChunk(F::pngChunk('iCCP', "big\x00\x00" . \gzcompress(\str_repeat("\x00", ImageHeader::ICC_LIMIT + 1)))));
check($bomb['icc_profile'] === true && $bomb['icc'] === null, 'png: profile inflating beyond ICC_LIMIT is not read');

$webp = static function (string $iccChunk): string {
	$body = 'VP8X' . \pack('V', 10) . "\x20\x00\x00\x00" . "\x3F\x00\x00\x2F\x00\x00" . $iccChunk;

	return 'RIFF' . \pack('V', 4 + \strlen($body)) . 'WEBP' . $body;
};
check(scanned(ImageFormat::Webp, $webp('ICCP' . \pack('V', \strlen($p3)) . $p3))['icc'] === $p3, 'webp: ICCP chunk');
check(scanned(ImageFormat::Webp, $webp('ICCP' . \pack('V', \strlen($p3) + 50) . $p3))['icc'] === null, 'webp: truncated ICCP chunk');

$tiff = static function (string $profile, int $count, int $photometric = 2): string {
	$entries = [[0x0100, 3, 1, 20], [0x0101, 3, 1, 10], [0x0106, 3, 1, $photometric]];
	$head = 'II' . \pack('v', 42) . \pack('V', 8) . \pack('v', \count($entries) + 1);
	$offset = 8 + 2 + 12 * (\count($entries) + 1) + 4;

	foreach ($entries as [$tag, $type, $n, $value]) {
		$head .= \pack('vvVvv', $tag, $type, $n, $value, 0);
	}

	return $head . \pack('vvVV', 0x8773, 7, $count, $offset) . \pack('V', 0) . $profile;
};
check(scanned(ImageFormat::Tiff, $tiff($p3, \strlen($p3)))['icc'] === $p3, 'tiff: InterColorProfile tag');
check(scanned(ImageFormat::Tiff, $tiff($p3, \strlen($p3) + 10))['icc'] === null, 'tiff: profile count beyond data');
check(scanned(ImageFormat::Tiff, $tiff($p3, \strlen($p3), 5))['cmyk'] === true, 'tiff: photometric separated is CMYK');

$gif = F::encode(F::quadrants(20, 10), 'gif');
$table = 3 * (1 << ((\ord($gif[10]) & 0x07) + 1));
$blocks = \implode('', \array_map(static fn(string $block): string => \chr(\strlen($block)) . $block, \str_split($p3, 255))) . "\x00";
$gifIcc = \substr($gif, 0, 13 + $table) . "\x21\xFF\x0BICCRGBG1012" . $blocks . \substr($gif, 13 + $table);
check(scanned(ImageFormat::Gif, $gifIcc)['icc'] === $p3, 'gif: ICCRGBG1 application extension');

$heifProf = heifOf(F::heifContainer('heic', 40, 30, [['colr', 'prof']]));
check($heifProf['icc_profile'] === true && $heifProf['icc'] === 'fake profile', 'heif: colr prof bytes');
check(heifOf(F::heifContainer('heic', 40, 30, [['colr', 'nclx']]))['nclx'] === ['primaries' => 1, 'transfer' => 13, 'matrix' => 6], 'heif: nclx code points');

check(\strlen(ProbeSamples::colorManaged()) > 0 && scanned(ImageFormat::Png, ProbeSamples::colorManaged())['icc'] === $p3, 'probe sample carries the shipped Display P3 profile');

// -- 3. Policy on a runtime without color management ---------------------------------------

$gd = new Image(testApp(['backends' => ['gd']]));
$encodePixel = static function (Image $service, string $data, array $options = []): array {
	$result = $service->encodeString($data, ['o' => ['format' => 'png']], $options)['o'];
	return [pixel(gd($result['data']), 2, 2), $result['backend']];
};

$solid = static function (array $rgb, ?string $profile, string $format = 'png'): string {
	$canvas = \imagecreatetruecolor(16, 12);
	\imagefill($canvas, 0, 0, \imagecolorallocate($canvas, ...$rgb));
	$data = F::encode($canvas, $format);

	if ($profile === null) {
		return $data;
	}

	return $format === 'png'
		? \substr($data, 0, 33) . F::pngChunk('iCCP', "profile\x00\x00" . \gzcompress($profile)) . \substr($data, 33)
		: \substr($data, 0, 2) . F::segment(0xE2, "ICC_PROFILE\x00\x01\x01" . $profile) . \substr($data, 2);
};

$p3Png = $solid([200, 60, 60], $p3);
$p3Jpeg = $solid([200, 60, 60], $p3, 'jpeg');
$srgbPng = $solid([200, 60, 60], $srgb);

$inspected = [
	'untagged png' => [$solid([200, 60, 60], null), 'srgb', false],
	'sRGB-tagged png' => [$srgbPng, 'srgb', true],
	'P3 png' => [$p3Png, 'display-p3', true],
	'P3 jpeg' => [$p3Jpeg, 'display-p3', true],
	'Adobe RGB png' => [$solid([200, 60, 60], Icc::adobeRgb()), 'adobe-rgb', true],
	'ProPhoto png' => [$solid([200, 60, 60], Icc::proPhoto()), 'rgb', true],
	'CMYK-profile png' => [$solid([200, 60, 60], Icc::cmyk()), 'cmyk', true],
	'unreadable profile' => [F::iccPng(), 'other', true],
	'heif nclx sRGB' => [F::heifContainer('heic', 40, 30, [['colr', 'nclx']]), 'srgb', false],
];

foreach ($inspected as $label => [$data, $space, $icc]) {
	$meta = $gd->inspectString($data);
	check($meta['color_space'] === $space && $meta['icc_profile'] === $icc, "inspect $label: color_space $space");
}

// nclx (ITU-T H.273): only the sRGB transfer is sRGB; unspecified (2) follows the
// untagged convention; BT.709 transfer, BT.2020, PQ, and HLG are not sRGB.
foreach ([
	[1, 13, 'srgb'], [2, 2, 'srgb'], [1, 2, 'srgb'], [2, 13, 'srgb'],
	[1, 1, 'other'], [1, 6, 'other'], [2, 1, 'other'],
	[12, 13, 'display-p3'], [12, 2, 'display-p3'], [12, 16, 'other'], [12, 1, 'other'],
	[9, 16, 'other'], [9, 13, 'other'], [9, 18, 'other'],
] as [$primaries, $transfer, $space]) {
	check($gd->inspectString(F::heifContainer('heic', 40, 30, [['colr', [$primaries, $transfer]]]))['color_space'] === $space, "nclx $primaries/$transfer is $space");
}

$caps = $gd->capabilities();
check($caps['color_management'] === null, 'gd: no color management');

$error = expectThrows(ImageCapabilityException::class, static fn() => $encodePixel($gd, $p3Png), 'gd: P3 source needs color management');
check(\str_contains($error->getMessage(), 'display-p3') && \str_contains($error->getMessage(), '"ignore"'), 'error names the color space and the opt-out');
[$pixel, $backend] = $encodePixel($gd, $p3Png, ['color' => 'ignore']);
check($pixel === [200, 60, 60, 0] && $backend === 'gd', 'gd: color => ignore keeps pixel values');
[$pixel, $backend] = $encodePixel($gd, $srgbPng);
check($pixel === [200, 60, 60, 0] && $backend === 'gd', 'gd: sRGB-equivalent profile needs no conversion');
// A profile the package cannot read (here: too short to be ICC). A JPEG carrier keeps
// libpng from printing its own iCCP warnings while GD decodes it.
$unreadable = jpegWithChunks([[1, 1, \str_repeat("\x00", 64)]]);
check($gd->inspectString($unreadable)['color_space'] === 'other', 'inspect jpeg with an unreadable profile');
expectThrows(ImageCapabilityException::class, static fn() => $encodePixel($gd, $unreadable), 'gd: unreadable profile cannot be honored under srgb');
check($encodePixel($gd, $unreadable, ['color' => 'ignore'])[1] === 'gd', 'gd: unreadable profile accepted with ignore');
expectThrows(ImageCapabilityException::class, static fn() => $gd->encodeString($srgbPng, ['o' => ['format' => 'png', 'overlays' => [['data' => $p3Png]]]]), 'gd: P3 overlay needs color management too');

// A profile with a device-to-PCS LUT is never shortcut as sRGB, even with sRGB matrix tags.
expectThrows(ImageCapabilityException::class, static fn() => $encodePixel($gd, $solid([200, 60, 60], Icc::withTag($srgb, 'A2B0', Icc::lutTag()))), 'gd: sRGB matrix with a LUT still needs color management');

// Output color labels are verified after encoding: sRGB pixels with a P3 label are rejected.
CountingBackend::reset();
CountingBackend::$mislabelOutput = true;
$counting = new Image(testApp(['backends' => ['gd']]), ['backends' => ['gd' => CountingBackend::class]]);
$labelError = expectThrows(ImageCapabilityException::class, static fn() => $counting->encodeString($srgbPng, ['o' => ['format' => 'png']]), 'mislabeled output rejected (memory)');
check(\str_contains($labelError->getMessage(), 'other than sRGB'), 'error names the color label problem');
$labelDir = tempDir();
expectThrows(ImageCapabilityException::class, static fn() => $counting->saveString($srgbPng, ['o' => ['path' => "$labelDir/out.png", 'format' => 'png']]), 'mislabeled output rejected (file)');
check(\glob("$labelDir/{,.}*.png*", \GLOB_BRACE) === [], 'mislabeled output: nothing published, no temporary files');
CountingBackend::reset();

// Declared support is not trusted: an engine that ignores profiles fails the probe.
$lying = new Image(testApp(['backends' => ['gd']]), ['backends' => ['gd' => ColorIgnoringBackend::class]]);
check($lying->capabilities()['color_management'] === null, 'declared but non-working color management is not reported');
expectThrows(ImageCapabilityException::class, static fn() => $lying->encodeString($p3Png, ['o' => ['format' => 'png']]), 'declared but non-working color management is not used');

$ignoring = new Image(testApp(['backends' => ['gd'], 'color' => 'ignore']));
check($ignoring->encodeString($p3Png, ['o' => ['format' => 'png']])['o']['backend'] === 'gd', 'cfg color ignore is the default for calls');
expectThrows(ImageCapabilityException::class, static fn() => $ignoring->encodeString($p3Png, ['o' => ['format' => 'png']], ['color' => 'srgb']), 'option color overrides cfg');
expectThrows(\UnexpectedValueException::class, static fn() => new Image(testApp(['color' => 'convert'])), 'cfg color validated');
expectThrows(\InvalidArgumentException::class, static fn() => $gd->encodeString($srgbPng, ['o' => ['format' => 'png']], ['color' => 'p3']), 'option color validated');

// -- 4. Color management on Imagick ------------------------------------------------------------

if (!imagickAvailable()) {
	echo "  info: color management tests skipped (ext-imagick not loaded)\n";
	done('color_test');
	exit(0);
}

$imagick = new Image(testApp(['backends' => ['imagick']]));
$both = new Image(testApp(['backends' => ['gd', 'imagick']]));

if ($imagick->capabilities()['color_management'] === null) {
	echo "  info: color management tests skipped (ImageMagick without LittleCMS)\n";
	done('color_test');
	exit(0);
}

check($both->capabilities()['color_management'] === 'imagick', 'imagick provides verified color management');

/** Build a 16x12 source with Imagick: color in a given space, profile embedded. */
$source = static function (string $color, ?string $profile, string $format, int $colorspace = \Imagick::COLORSPACE_SRGB): string {
	$image = new \Imagick();
	$image->newImage(16, 12, new \ImagickPixel($color));

	if ($colorspace !== \Imagick::COLORSPACE_SRGB) {
		$image->setImageColorspace($colorspace);
	}

	if ($profile !== null) {
		$image->setImageProfile('icc', $profile);
	}

	$image->setImageFormat($format);
	$image->setImageCompressionQuality(100);

	return $image->getImageBlob();
};

$close = static fn(array $pixel, array $expected, int $tolerance): bool => \abs($pixel[0] - $expected[0]) <= $tolerance && \abs($pixel[1] - $expected[1]) <= $tolerance && \abs($pixel[2] - $expected[2]) <= $tolerance;

// Reference values: LittleCMS (Pillow ImageCms), perceptual intent, to sRGB-v4.
$cases = [
	'P3 png' => [$source('rgb(200,60,60)', $p3, 'PNG'), [217, 42, 52], 2],
	'P3 jpeg' => [$source('rgb(200,60,60)', $p3, 'JPEG'), [217, 42, 52], 4],
	'P3 tiff' => [$source('rgb(200,60,60)', $p3, 'TIFF'), [217, 42, 52], 2],
	'P3 webp' => [$source('rgb(200,60,60)', $p3, 'WEBP'), [217, 42, 52], 8],
	'Adobe RGB png' => [$source('rgb(200,60,60)', Icc::adobeRgb(), 'PNG'), [231, 57, 57], 2],
	'ProPhoto png' => [$source('rgb(200,60,60)', Icc::proPhoto(), 'PNG'), [255, 0, 74], 3],
	'Rec2020 png' => [$source('rgb(200,60,60)', Icc::rec2020(), 'PNG'), [252, 11, 70], 3],
	'CMYK jpeg (CGATS001)' => [$source('cmyk(51,178,160,10)', Icc::cmyk(), 'JPEG', \Imagick::COLORSPACE_CMYK), [195, 104, 93], 3],
];

foreach (['HEIC' => 'heic', 'AVIF' => 'avif'] as $magick => $format) {
	if ($imagick->capabilities()['encode'][$format] === 'imagick') {
		$cases["P3 $format"] = [$source('rgb(200,60,60)', $p3, $magick), [217, 42, 52], 8];
	}
}

foreach ($cases as $label => [$data, $expected, $tolerance]) {
	$meta = $both->inspectString($data);
	$result = $both->encodeString($data, ['o' => ['format' => 'png']])['o'];
	$pixel = pixel(gd($result['data']), 4, 4);
	check($meta['color_space'] !== 'srgb' && $result['backend'] === 'imagick', "$label: routed to the color-managed backend");
	check($close($pixel, $expected, $tolerance), "$label: converted to sRGB reference " . \implode(',', $expected) . ' (got ' . \implode(',', \array_slice($pixel, 0, 3)) . ')');
	check(!\str_contains($result['data'], 'iCCP'), "$label: output is untagged sRGB");
}

// Every output of a converted source describes sRGB: no ICC, sRGB or no CICP, sRGB chromaticities.
$p3Source = $source('rgb(200,60,60)', $p3, 'PNG');

foreach (ImageFormat::cases() as $format) {
	if ($imagick->capabilities()['encode'][$format->value] !== 'imagick') {
		continue;
	}

	$encoded = $imagick->encodeString($p3Source, ['o' => ['format' => $format->value]])['o']['data'];
	$meta = $imagick->inspectString($encoded);
	check($meta['color_space'] === 'srgb' && $meta['icc_profile'] !== true, "{$format->value} output of a P3 source is labeled sRGB");
}

// PNG output carries no color chunks of its own, whatever the source had.
$pngChunks = static function (string $png): array {
	$chunks = [];

	for ($at = 8; $at + 8 <= \strlen($png); $at += 12 + \unpack('N', $png, $at)[1]) {
		$chunks[] = \substr($png, $at + 4, 4);
	}

	return $chunks;
};

// Chromaticities a decoder read (PNG cHRM) do not reach TIFF output.
$chrm = new \Imagick();
$chrm->newImage(16, 12, new \ImagickPixel('rgb(200,60,60)'));
F::chromaticity($chrm, 'setImageRedPrimary', 0.68, 0.32);
F::chromaticity($chrm, 'setImageGreenPrimary', 0.265, 0.69);
$chrm->setOption('png:exclude-chunk', 'sRGB');
$chrm->setImageFormat('PNG');
$withChrm = $chrm->getImageBlob();
check(\str_contains($withChrm, 'cHRM'), 'fixture carries cHRM');
$tiffOut = new \Imagick();
$tiffOut->readImageBlob($imagick->encodeString($withChrm, ['o' => ['format' => 'tiff']])['o']['data']);
$red = $tiffOut->getImageRedPrimary();
check(\abs($red['x'] - 0.64) < 0.001 && \abs($red['y'] - 0.33) < 0.001, 'tiff output chromaticities are sRGB, not the source cHRM');
$pngOut = $imagick->encodeString($withChrm, ['o' => ['format' => 'png']])['o']['data'];
check(\array_intersect($pngChunks($pngOut), ['gAMA', 'cHRM', 'sRGB', 'iCCP', 'cICP']) === [], 'png output from a cHRM source has no color chunks');
$pngAlpha = $imagick->encodeString($source('rgba(200,60,60,0.5)', $p3, 'PNG'), ['o' => ['format' => 'png']])['o']['data'];
check(\array_intersect($pngChunks($pngAlpha), ['gAMA', 'cHRM', 'sRGB', 'iCCP', 'cICP']) === [] && \abs(pixel(gd($pngAlpha), 4, 4)[3] - 64) <= 2, 'png output with alpha: no color chunks, alpha kept through conversion');

// Gray with a non-sRGB curve (gamma 461/256): analytic reference.
$gray18 = Icc::withCurve(Icc::sGrey(), Icc::gammaCurve(461 / 256));
$grayImage = new \Imagick();
$grayImage->newImage(16, 12, new \ImagickPixel('rgb(100,100,100)'));
$grayImage->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
$grayImage->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
$grayImage->setImageProfile('icc', $gray18);
$grayImage->setImageFormat('PNG');
$linear = (100 / 255) ** (461 / 256);
$reference = (int)\round((1.055 * $linear ** (1 / 2.4) - 0.055) * 255);
$pixel = pixel(gd($both->encodeString($grayImage->getImageBlob(), ['o' => ['format' => 'png']])['o']['data']), 4, 4);
check($both->inspectString($grayImage->getImageBlob())['color_space'] === 'gray' && $close($pixel, [$reference, $reference, $reference], 2), "gray gamma 1.8 converted to sRGB $reference");

// HEIF nclx color information (no embedded profile), with whichever HEIF encoder exists.
$heifEncoder = $imagick->capabilities()['encode']['heic'] === 'imagick' ? 'HEIC' : ($imagick->capabilities()['encode']['avif'] === 'imagick' ? 'AVIF' : null);

if ($heifEncoder !== null) {
	$raw = $source('rgb(200,60,60)', null, $heifEncoder);
	$nclxP3 = F::withHeifNclx($raw, 12, 13);
	check($both->inspectString($nclxP3)['color_space'] === 'display-p3', 'heif nclx P3 recognized');
	$pixel = pixel(gd($both->encodeString($nclxP3, ['o' => ['format' => 'png']])['o']['data']), 4, 4);
	check($close($pixel, [217, 42, 52], 8), 'heif nclx P3 converted with the shipped Display P3 profile');
	$hdr = F::withHeifNclx($raw, 9, 16);
	expectThrows(ImageCapabilityException::class, static fn() => $both->encodeString($hdr, ['o' => ['format' => 'png']]), 'heif nclx BT.2020/PQ cannot be converted under srgb');
	check($both->encodeString($hdr, ['o' => ['format' => 'png']], ['color' => 'ignore'])['o']['format'] === 'png', 'heif nclx BT.2020/PQ accepted with ignore');
	expectThrows(ImageCapabilityException::class, static fn() => $both->encodeString(F::withHeifNclx($raw, 1, 1), ['o' => ['format' => 'png']]), 'heif nclx BT.709 transfer is not treated as sRGB');

	// Same-format output: the source CICP must not be carried over onto sRGB pixels.
	$sameFormat = $both->encodeString($nclxP3, ['o' => ['format' => \strtolower($heifEncoder)]])['o']['data'];
	check($both->inspectString($sameFormat)['color_space'] === 'srgb', "P3 {$heifEncoder} to {$heifEncoder}: output CICP describes sRGB");
	echo '  info: heif nclx tests use ' . $heifEncoder . "\n";
} else {
	echo "  info: heif nclx tests skipped (no heic or avif encoder)\n";
}

// Policy, routing, overlays, mismatch.
[$pixel] = $encodePixel($imagick, $source('rgb(200,60,60)', $p3, 'PNG'), ['color' => 'ignore']);
check($pixel[0] === 200 && $pixel[1] === 60 && $pixel[2] === 60, 'imagick: color => ignore keeps pixel values');
check($both->encodeString($srgbPng, ['o' => ['format' => 'png']])['o']['backend'] === 'gd', 'sRGB sources keep the preferred backend');

$overlayOnly = $both->encodeString(F::encode(F::quadrants(40, 20), 'png'), ['o' => ['format' => 'png', 'overlays' => [['data' => $source('rgb(200,60,60)', $p3, 'PNG'), 'anchor' => 'top-left']]]])['o'];
check($overlayOnly['backend'] === 'imagick' && $close(pixel(gd($overlayOnly['data']), 4, 4), [217, 42, 52], 2), 'P3 overlay converted before compositing');

$rgbWithCmyk = \substr(F::encode(F::quadrants(16, 12), 'png'), 0, 33) . F::pngChunk('iCCP', "cmyk\x00\x00" . \gzcompress(Icc::cmyk())) . \substr(F::encode(F::quadrants(16, 12), 'png'), 33);
expectThrows(ImageInputException::class, static fn() => $both->encodeString($rgbWithCmyk, ['o' => ['format' => 'png']]), 'RGB data with a CMYK profile is rejected');

// Untagged CMYK: approximate working-colorspace conversion under either policy.
$untaggedCmyk = $source('cmyk(51,178,160,10)', null, 'JPEG', \Imagick::COLORSPACE_CMYK);
check($both->inspectString($untaggedCmyk)['color_space'] === 'cmyk' && $both->inspectString($untaggedCmyk)['icc_profile'] === false, 'untagged CMYK detected');
check($both->encodeString($untaggedCmyk, ['o' => ['format' => 'png']])['o']['format'] === 'png', 'untagged CMYK converts without a profile');

// Same sRGB source, both backends: same pixels.
$gdPixel = pixel(gd($gd->encodeString($srgbPng, ['o' => ['format' => 'png']])['o']['data']), 4, 4);
$imPixel = pixel(gd($imagick->encodeString($srgbPng, ['o' => ['format' => 'png']])['o']['data']), 4, 4);
check($gdPixel === $imPixel, 'sRGB-tagged source: identical pixels on gd and imagick');

done('color_test');

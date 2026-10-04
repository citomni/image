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
 * End-to-end checks for the Image service pinned to the GD backend:
 * inspection, capabilities, orientation, geometry, alpha, overlays, input
 * policy, request validation, determinism, sibling independence, and
 * decode/resample counts. Imagick and cross-backend parity: imagick_test.php.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageInputException;
use CitOmni\Image\Service\Image;
use CitOmni\Image\Tests\Support\CountingBackend;
use CitOmni\Image\Tests\Support\ImageFixtures as F;

$gdOnly = ['backends' => ['gd']];
$image = new Image(testApp($gdOnly));
$dir = tempDir();

$put = static function (string $name, string $data) use ($dir): string {
	\file_put_contents($dir . '/' . $name, $data);
	return $dir . '/' . $name;
};

/** Encode one PNG output from bytes and return it as a GD image plus its result. */
$one = static function (string $data, array $output, array $options = []) use ($image): array {
	$result = $image->encodeString($data, ['out' => $output + ['format' => 'png']], $options)['out'];
	return [gd($result['data']), $result];
};

// -- Configuration ------------------------------------------------------------------

expectThrows(\UnexpectedValueException::class, static fn() => new Image(testApp(['jpeg' => ['quality' => '80']])), 'cfg type checked');
expectThrows(\UnexpectedValueException::class, static fn() => new Image(testApp(['background' => 'white'])), 'cfg background checked');
expectThrows(\UnexpectedValueException::class, static fn() => new Image(testApp(['max_pixels' => \PHP_INT_MAX])), 'cfg max_pixels ceiling');
expectThrows(\UnexpectedValueException::class, static fn() => new Image(testApp(['avif' => ['speed' => 10]])), 'avif speed 10 is outside the backend-neutral range');
check(new Image(testApp(['avif' => ['speed' => 9]])) instanceof Image, 'avif speed 9 accepted');
expectThrows(\UnexpectedValueException::class, static fn() => new Image((object)['cfg' => cfgTree(['image' => ['backends' => ['vips']] + \CitOmni\Image\Boot\Registry::CFG_COMMON['image']])]), 'unknown backend rejected');

// -- Inspection ---------------------------------------------------------------------

$jpeg6 = F::jpeg(6, false, true);
$meta = $image->inspectString($jpeg6);
check($meta['format'] === 'jpeg' && $meta['mime'] === 'image/jpeg', 'inspect format');
check([$meta['width'], $meta['height'], $meta['display_width'], $meta['display_height']] === [301, 199, 199, 301], 'inspect stored and display size');
check($meta['orientation'] === 6 && $meta['icc_profile'] === true && $meta['alpha'] === false && $meta['multi_frame'] === false, 'inspect flags');
check($meta['bytes'] === \strlen($jpeg6) && $meta['decoder'] === 'gd', 'inspect bytes and decoder');
check($image->inspect($put('o6.jpg', $jpeg6)) === $meta, 'inspect file equals inspectString');

$tiff = $image->inspectString(F::tiffFile(40, 30, 6));
check($tiff['format'] === 'tiff' && $tiff['orientation'] === 6 && $tiff['decoder'] === null, 'tiff inspected, no decoder');
check($image->inspectString(F::apng())['multi_frame'] === true, 'inspect APNG');

expectThrows(ImageInputException::class, static fn() => $image->inspectString(''), 'empty data');
expectThrows(ImageInputException::class, static fn() => $image->inspectString('definitely not an image'), 'garbage');
expectThrows(ImageInputException::class, static fn() => $image->inspectString(F::ftyp('heic', ['mif1', 'heic'])), 'heic brand without meta box');

$heic = F::heifContainer('heic', 64, 64, [['clap', [64, 1, 48, 1, 0, 2, 0xFFFFFFF0, 2]], ['irot', 3]], ['mif1', 'heic']);
$meta = $image->inspectString($heic);
check($meta['format'] === 'heic' && $meta['mime'] === 'image/heic', 'heic recognized without any backend');
check([$meta['width'], $meta['height'], $meta['orientation'], $meta['display_width'], $meta['display_height']] === [64, 48, 6, 48, 64], 'heic clean aperture and irot');
check($meta['decoder'] === null, 'heic has no gd decoder');
expectThrows(ImageCapabilityException::class, static fn() => $image->encodeString($heic, ['o' => ['format' => 'png']]), 'heic decode capability on gd');

// HEIF hardening: the pixel budget covers the coded frame, not just the clean aperture.
$hugeFrame = F::heifContainer('heic', 100_000, 100_000, [['clap', [100, 1, 100, 1, 0, 1, 0, 1]]], ['mif1', 'heic']);
check($image->inspectString($hugeFrame)['width'] === 100, 'inspect reports the clean aperture');
$error = expectThrows(ImageInputException::class, static fn() => $image->encodeString($hugeFrame, ['o' => ['format' => 'png']]), 'huge coded frame rejected by policy before backend selection');
check(\str_contains($error->getMessage(), 'coded frame is 100000x100000'), 'message names the coded frame');

// A container whose declared box sizes exceed the file is rejected without large reads.
$meta = "\x00\x00\x00\x00" . F::isoBox('pitm', "\x00\x00\x00\x00\x00\x01") . \pack('N', 0xFFFFFF00) . 'iprp' . \pack('N', 0xFFFFFF00 - 8) . 'ipma';
$lyingPath = $put('lying.heic', F::isoBox('ftyp', "heic\x00\x00\x00\x00heicmif1") . \pack('N', 8 + \strlen($meta) + 0xFFFFFF00) . 'meta' . $meta);
$before = \memory_get_peak_usage();
expectThrows(ImageInputException::class, static fn() => $image->inspect($lyingPath), 'container with impossible box sizes rejected');
check(\memory_get_peak_usage() - $before < 1_048_576, 'rejected without allocating the declared size');

// HEIF data larger than its uncompressed primary image is refused before decoding.
$padded = F::heifContainer('heic', 10, 10, [], ['mif1', 'heic']) . F::isoBox('mdat', \str_repeat("\x00", 2_000_000));
expectThrows(ImageInputException::class, static fn() => $image->encodeString($padded, ['o' => ['format' => 'png']]), 'oversized heif payload rejected');
expectThrows(\RuntimeException::class, static fn() => $image->inspect($dir . '/missing.jpg'), 'missing file');

// -- Capabilities -------------------------------------------------------------------

$caps = $image->capabilities();
check($caps['backends']['gd']['available'] === true && \is_string($caps['backends']['gd']['version']), 'gd reported');

foreach (['jpeg', 'png', 'webp', 'bmp'] as $format) {
	check($caps['decode'][$format] === 'gd' && $caps['encode'][$format] === 'gd', "gd round-trips $format");
}

check($caps['decode']['gif'] === 'gd' && $caps['encode']['gif'] === null, 'gif decode only');
check($caps['first_frame']['gif'] === 'gd' && $caps['first_frame']['png'] === 'gd' && $caps['first_frame']['webp'] === null, 'first-frame capability is probed with real two-frame samples');
check($caps['decode']['tiff'] === null && $caps['decode']['heic'] === null, 'tiff/heic unsupported on gd');
check($caps['color_management'] === null, 'gd has no color management');
$avif = $caps['encode']['avif'];

// -- Orientation: display corners for EXIF 1-8 (stored TL red, TR green, BL blue, BR yellow)

$expected = [
	1 => [F::RED, F::GREEN], 2 => [F::GREEN, F::RED], 3 => [F::YELLOW, F::BLUE], 4 => [F::BLUE, F::YELLOW],
	5 => [F::RED, F::BLUE], 6 => [F::BLUE, F::RED], 7 => [F::YELLOW, F::GREEN], 8 => [F::GREEN, F::YELLOW],
];

foreach ($expected as $orientation => [$topLeft, $topRight]) {
	foreach ([null, 120] as $width) {
		[$out, $result] = $one(F::jpeg($orientation), ['width' => $width]);
		$swap = $orientation >= 5;
		$expectedSize = $width === null ? ($swap ? [199, 301] : [301, 199]) : ($swap ? [120, 182] : [120, 79]);
		check([$result['width'], $result['height']] === $expectedSize && [\imagesx($out), \imagesy($out)] === $expectedSize, "orientation $orientation size (width $width)");
		check(near(pixel($out, 2, 2), $topLeft), "orientation $orientation top-left (width $width)");
		check(near(pixel($out, \imagesx($out) - 3, 2), $topRight), "orientation $orientation top-right (width $width)");
	}
}

[$out] = $one(F::jpeg(6), [], ['auto_orient' => false]);
check(\imagesx($out) === 301 && near(pixel($out, 2, 2), F::RED), 'auto_orient false keeps stored orientation');

// rotate composes after EXIF orientation; crop is in final display space.
[$out] = $one(F::jpeg(6), ['rotate' => -90]);
check(\imagesx($out) === 301 && near(pixel($out, 2, 2), F::RED), 'EXIF 6 then rotate -90 is upright stored');
[$out, $result] = $one(F::jpeg(), ['rotate' => 90, 'crop' => [100, 0, 99, 150]]);
check([$result['width'], $result['height']] === [99, 150] && near(pixel($out, 50, 75), F::RED), 'crop after rotate');
[$out, $result] = $one(F::jpeg(8), ['crop' => [0, 160, 99, 141], 'width' => 33, 'fit' => 'contain']);
check([$result['width'], $result['height']] === [33, 47] && near(pixel($out, 16, 20), F::RED), 'crop + fit on oriented source (display BL = stored TL)');

// -- Geometry and fits --------------------------------------------------------------

$quad = F::jpeg(null, false, false, 4000, 3000);
$sizes = static function (array $output, array $options = []) use ($image, $quad): array {
	$result = $image->encodeString($quad, ['o' => $output + ['format' => 'jpeg']], $options)['o'];
	return [$result['width'], $result['height']];
};

check($sizes(['width' => 800]) === [800, 600], 'contain width');
check($sizes(['width' => 800, 'height' => 800]) === [800, 600], 'contain box');
check($sizes(['width' => 300, 'height' => 300, 'fit' => 'cover']) === [300, 300], 'cover');
check($sizes(['width' => 300, 'height' => 100, 'fit' => 'fill']) === [300, 100], 'fill');
check($sizes(['width' => 5000]) === [4000, 3000], 'no implicit upscale');
check($sizes(['width' => 4400, 'upscale' => true]) === [4400, 3300], 'explicit upscale');
expectThrows(ImageInputException::class, static fn() => $sizes(['width' => 6000, 'upscale' => true]), 'upscaled output above max_pixels');

[$out] = $one(F::jpeg(null, false, false, 400, 200), ['width' => 100, 'height' => 100, 'fit' => 'cover']);
check(near(pixel($out, 5, 5), F::RED) && near(pixel($out, 94, 94), F::YELLOW), 'cover is centered');

$wide = F::encode(F::quadrants(17_000, 10), 'png');
expectThrows(ImageInputException::class, static fn() => $image->encodeString($wide, ['o' => ['format' => 'webp']]), 'derived size above webp limit');
check($image->encodeString($wide, ['o' => ['format' => 'webp', 'width' => 16_000]])['o']['width'] === 16_000, 'bounded webp ok');

// -- Alpha and flattening -----------------------------------------------------------

$alpha = F::alphaPng();
$results = $image->encodeString($alpha, [
	'jpeg' => ['format' => 'jpeg'],
	'jpeg_black' => ['format' => 'jpeg', 'background' => '#000000'],
	'webp' => ['format' => 'webp'],
	'png' => ['format' => 'png'],
	'bmp' => ['format' => 'bmp', 'background' => '#00ff00'],
]);
check(near(pixel(gd($results['jpeg']['data']), 10, 10), [255, 255, 255], 8), 'jpeg flattened onto cfg background, not black');
check(near(pixel(gd($results['jpeg']['data']), 150, 10), [255, 0, 255], 8), 'jpeg opaque area kept');
check(near(pixel(gd($results['jpeg_black']['data']), 10, 10), [0, 0, 0], 8), 'per-output background');
check(pixel(gd($results['webp']['data']), 10, 10)[3] === 127, 'webp keeps alpha');
check(pixel(gd($results['png']['data']), 10, 10)[3] === 127 && pixel(gd($results['png']['data']), 150, 10)[3] === 0, 'png keeps alpha');
check(near(pixel(gd($results['bmp']['data']), 10, 10), [0, 255, 0], 0), 'bmp flattened');

foreach (['gif' => F::transparentGif(), 'png' => F::palettePng()] as $kind => $palette) {
	[$out] = $one($palette, []);
	check(pixel($out, 10, 10)[3] === 127, "$kind palette transparency becomes alpha");
	check(pixel($out, 75, 10) === [0, 0, 0, 0], "$kind opaque black stays opaque");
	check(pixel($out, 10, 75) === [255, 255, 255, 0], "$kind white kept");
	[$out] = $one($palette, ['crop' => [0, 0, 100, 60], 'rotate' => 180]);
	check(pixel($out, 10, 10) === [0, 0, 0, 0] && pixel($out, 80, 50)[3] === 127, "$kind crop+rotate keeps keyed pixels exact");
	$flat = gd($image->encodeString($palette, ['o' => ['format' => 'jpeg']])['o']['data']);
	check(near(pixel($flat, 10, 10), [255, 255, 255], 8) && near(pixel($flat, 75, 10), [0, 0, 0], 8), "$kind flatten");
}

// -- Input policy -------------------------------------------------------------------

expectThrows(ImageInputException::class, static fn() => $one(F::animatedGif(), []), 'animated gif rejected by default');
[$out] = $one(F::animatedGif(), [], ['multi_frame' => 'first']);
check(near(pixel($out, 1, 1), F::RED, 0), 'first frame of animated gif');
expectThrows(ImageInputException::class, static fn() => $one(F::apng(), []), 'APNG rejected by default');
check($one(F::apng(), [], ['multi_frame' => 'first'])[1]['width'] === 40, 'APNG default image with opt-in');
expectThrows(ImageInputException::class, static fn() => $one(F::webpVp8x(0x02), []), 'animated webp rejected by default');
expectThrows(ImageCapabilityException::class, static fn() => $one(F::webpVp8x(0x02), [], ['multi_frame' => 'first']), 'animated webp first frame unsupported on gd');

$plain = F::jpeg();
expectThrows(ImageInputException::class, static fn() => $one(\substr($plain, 0, \intdiv(\strlen($plain), 2)), []), 'truncated jpeg rejected');
$gif = F::transparentGif();
expectThrows(ImageInputException::class, static fn() => $one(\substr($gif, 0, \intdiv(\strlen($gif), 2)), []), 'truncated gif rejected');
expectThrows(ImageInputException::class, static fn() => $one(\substr(F::alphaPng(), 0, 60), []), 'truncated png rejected by decoder');
expectThrows(ImageInputException::class, static fn() => $one(F::pngHeader(10_000, 10_000), []), 'max_pixels enforced from header');

$decodes = 0;
CountingBackend::reset();
$counting = new Image(testApp($gdOnly), ['backends' => ['gd' => CountingBackend::class]]);
$counting->capabilities(); // Run codec probes now, so later counts and faults concern jobs only.
CountingBackend::reset();
expectThrows(ImageInputException::class, static fn() => $counting->encodeString(F::pngHeader(10_000, 10_000), ['o' => ['format' => 'png']]), 'oversized rejected');
check(!isset(CountingBackend::$calls['decodeString']), 'oversized input never reaches the decoder');

expectThrows(ImageCapabilityException::class, static fn() => $one(F::tiffFile(40, 30, 1), []), 'tiff decode capability');
expectThrows(ImageCapabilityException::class, static fn() => $one($plain, ['format' => 'gif']), 'gif encode capability');
expectThrows(ImageCapabilityException::class, static fn() => $one($plain, ['format' => 'heic']), 'heic encode capability');

if ($avif === null) {
	expectThrows(ImageCapabilityException::class, static fn() => $one($plain, ['format' => 'avif']), 'avif unsupported -> capability failure, no substitution');
} else {
	foreach ([[640, 480], [641, 479], [64, 48], [17, 17]] as [$w, $h]) {
		$result = $image->encodeString(F::jpeg(null, false, false, $w, $h), ['o' => ['format' => 'avif']])['o'];
		$info = \getimagesizefromstring($result['data']);
		check($result['format'] === 'avif' && [$info[0], $info[1]] === [$w, $h], "avif round-trip {$w}x{$h}");
	}
}

// -- Request validation -------------------------------------------------------------

$invalid = [
	'unknown key' => ['format' => 'png', 'widht' => 10],
	'unknown format' => ['format' => 'jpg'],
	'png quality' => ['format' => 'png', 'quality' => 80],
	'jpeg compression' => ['format' => 'jpeg', 'compression' => 6],
	'webp background' => ['format' => 'webp', 'background' => '#000000'],
	'quality range' => ['format' => 'jpeg', 'quality' => 101],
	'cover without height' => ['format' => 'png', 'width' => 10, 'fit' => 'cover'],
	'fill without width' => ['format' => 'png', 'height' => 10, 'fit' => 'fill'],
	'unknown fit' => ['format' => 'png', 'width' => 10, 'fit' => 'stretch'],
	'zero width' => ['format' => 'png', 'width' => 0],
	'explicit width above webp limit' => ['format' => 'webp', 'width' => 20_000],
	'rotate 45' => ['format' => 'png', 'rotate' => 45],
	'crop shape' => ['format' => 'png', 'crop' => [0, 0, 10]],
	'crop outside image' => ['format' => 'png', 'crop' => [300, 0, 10, 10]],
	'path in encode()' => ['format' => 'png', 'path' => '/tmp/x.png'],
];

foreach ($invalid as $label => $output) {
	expectThrows(\InvalidArgumentException::class, static fn() => $image->encodeString($plain, ['o' => $output]), $label);
}

expectThrows(\InvalidArgumentException::class, static fn() => $image->encodeString($plain, ['o' => ['format' => 'png']], ['animation' => true]), 'unknown option');
expectThrows(\InvalidArgumentException::class, static fn() => $image->encodeString($plain, ['o' => ['format' => 'png']], ['multi_frame' => 'all']), 'bad multi_frame option');
check($image->encodeString('not even read', []) === [], 'empty output list does not read the source');

// -- Overlays (compositing / watermark) ----------------------------------------------

$solid = static function (int $width, int $height, array $rgb, int $alpha = 0): string {
	$canvas = \imagecreatetruecolor($width, $height);
	\imagealphablending($canvas, false);
	\imagesavealpha($canvas, true);
	\imagefill($canvas, 0, 0, \imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], $alpha));

	return F::encode($canvas, 'png');
};

$white = $solid(200, 100, [255, 255, 255]);
$mark = (static function (): string {
	// 20x10: left half fully transparent, right half opaque red.
	$canvas = \imagecreatetruecolor(20, 10);
	\imagealphablending($canvas, false);
	\imagesavealpha($canvas, true);
	\imagefilledrectangle($canvas, 0, 0, 9, 9, \imagecolorallocatealpha($canvas, 0, 0, 0, 127));
	\imagefilledrectangle($canvas, 10, 0, 19, 9, \imagecolorallocatealpha($canvas, 255, 0, 0, 0));

	return F::encode($canvas, 'png');
})();
$markPath = $put('mark.png', $mark);

[$out, $result] = $one($white, ['overlays' => [['file' => $markPath, 'anchor' => 'bottom-right', 'offset' => [-5, -5]]]]);
check([$result['width'], $result['height']] === [200, 100], 'overlay keeps output size');
check(pixel($out, 190, 90) === [255, 0, 0, 0], 'opaque overlay pixel at bottom-right with offset');
check(pixel($out, 180, 90) === [255, 255, 255, 0], 'transparent overlay pixel leaves the base');
check(pixel($out, 196, 96) === [255, 255, 255, 0] && pixel($out, 10, 10) === [255, 255, 255, 0], 'outside the overlay untouched');

[$out] = $one($white, ['overlays' => [['data' => $mark, 'anchor' => 'top-left', 'opacity' => 50]]]);
check(near(pixel($out, 15, 5), [255, 128, 128], 2) && pixel($out, 15, 5)[3] === 0, 'opacity 50 blends half way');

[$out] = $one($white, ['overlays' => [['data' => $mark, 'anchor' => 'center', 'opacity' => 0]]]);
check(pixel($out, 105, 50) === [255, 255, 255, 0], 'opacity 0 draws nothing');

[$out] = $one($white, ['overlays' => [['data' => $mark, 'anchor' => 'top-left', 'offset' => [-15, 0]]]]);
check(pixel($out, 2, 5) === [255, 0, 0, 0] && pixel($out, 6, 5) === [255, 255, 255, 0], 'overlay clipped at the left edge');

[$out] = $one($white, ['overlays' => [['data' => $mark, 'anchor' => 'center', 'width' => 400]]]);
check(pixel($out, 150, 50) === [255, 0, 0, 0] && pixel($out, 50, 50) === [255, 255, 255, 0], 'overlay larger than output is clipped');

// width_percent scales with each output; overlay sources decode once per job.
CountingBackend::reset();
$watermarked = $counting->encodeString($white, [
	'big' => ['format' => 'png', 'overlays' => [['file' => $markPath, 'anchor' => 'bottom-right', 'width_percent' => 50]]],
	'small' => ['format' => 'png', 'width' => 100, 'overlays' => [['file' => $markPath, 'anchor' => 'bottom-right', 'width_percent' => 50]]],
	'small_webp' => ['format' => 'webp', 'width' => 100, 'overlays' => [['file' => $markPath, 'anchor' => 'bottom-right', 'width_percent' => 50]]],
	'plain' => ['format' => 'png', 'width' => 100],
]);
check(pixel(gd($watermarked['big']['data']), 150, 80) === [255, 0, 0, 0] && pixel(gd($watermarked['big']['data']), 90, 80) === [255, 255, 255, 0], 'overlay at 50% of a 200px output is 100px wide');
check(pixel(gd($watermarked['small']['data']), 80, 45) === [255, 0, 0, 0] && pixel(gd($watermarked['small']['data']), 40, 45) === [255, 255, 255, 0], 'overlay at 50% of a 100px output is 50px wide');
check(pixel(gd($watermarked['plain']['data']), 90, 45) === [255, 255, 255, 0], 'outputs without overlays stay clean');
check((CountingBackend::$calls['decodeString'] ?? 0) === 1 && (CountingBackend::$calls['decodeFile'] ?? 0) === 1, 'source and overlay each decoded once');
check((CountingBackend::$calls['composite'] ?? 0) === 2, 'one composite per distinct geometry and overlay set');

CountingBackend::reset();
$counting->encodeString($white, [
	'a' => ['format' => 'png', 'overlays' => [['data' => $mark, 'width' => 40, 'opacity' => 60]]],
	'b' => ['format' => 'webp', 'width' => 150, 'overlays' => [['data' => $mark, 'width' => 40, 'opacity' => 60, 'anchor' => 'top-left']]],
	'c' => ['format' => 'png', 'overlays' => [['data' => $mark, 'opacity' => 0]]],
]);
check((CountingBackend::$calls['fade'] ?? 0) === 1, 'same overlay, size and opacity is faded once per job');
check((CountingBackend::$calls['composite'] ?? 0) === 2, 'opacity 0 overlays are not composited');

// Every handle a job creates is released, on success and on failure.
$leakJob = [
	'a' => ['format' => 'png', 'overlays' => [['data' => $mark, 'width' => 40, 'opacity' => 60]]],
	'b' => ['format' => 'webp', 'width' => 150, 'rotate' => 90, 'overlays' => [['data' => $mark, 'anchor' => 'top-left']]],
	'c' => ['format' => 'jpeg', 'width' => 80],
];
CountingBackend::reset();
$counting->encodeString(F::jpeg(6), $leakJob);
check(CountingBackend::$live === [], 'no handle left unreleased after a successful job');
CountingBackend::reset();
CountingBackend::$corruptOutput = true;
expectThrows(ImageCapabilityException::class, static fn() => $counting->encodeString(F::jpeg(6), $leakJob), 'verification failure mid-job');
check(CountingBackend::$live === [], 'no handle left unreleased after a failure inside a group');
CountingBackend::reset();

$alone = $image->encodeString($white, ['small' => ['format' => 'png', 'width' => 100, 'overlays' => [['file' => $markPath, 'anchor' => 'bottom-right', 'width_percent' => 50]]]]);
check($alone['small']['data'] === $watermarked['small']['data'], 'overlaid output independent of siblings');

// Overlays compose with alpha on transparent bases and flatten correctly afterwards.
$clear = $solid(60, 40, [0, 0, 0], 127);
$composited = $image->encodeString($clear, [
	'png' => ['format' => 'png', 'overlays' => [['data' => $mark, 'anchor' => 'top-left', 'opacity' => 50]]],
	'jpeg' => ['format' => 'jpeg', 'overlays' => [['data' => $mark, 'anchor' => 'top-left']]],
]);
$pngOut = gd($composited['png']['data']);
check(pixel($pngOut, 15, 5)[0] === 255 && \abs(pixel($pngOut, 15, 5)[3] - 63) <= 1, 'half-opaque overlay on transparent base keeps half alpha');
check(pixel($pngOut, 40, 30)[3] === 127, 'transparent base stays transparent outside the overlay');
check(near(pixel(gd($composited['jpeg']['data']), 15, 5), [255, 0, 0], 12) && near(pixel(gd($composited['jpeg']['data']), 40, 30), [255, 255, 255], 8), 'flatten after compositing, no black');

// Overlays are oriented like any source.
[$out] = $one($white, ['overlays' => [['data' => F::jpeg(6, false, false, 40, 20), 'anchor' => 'top-left']]]);
check(near(pixel($out, 2, 2), F::BLUE) && near(pixel($out, 17, 2), F::RED), 'overlay EXIF orientation applied (display TL blue, TR red)');

$overlayInvalid = [
	'both sources' => ['file' => $markPath, 'data' => $mark],
	'no source' => ['anchor' => 'center'],
	'unknown anchor' => ['data' => $mark, 'anchor' => 'middle'],
	'width and percent' => ['data' => $mark, 'width' => 10, 'width_percent' => 10],
	'percent range' => ['data' => $mark, 'width_percent' => 101],
	'opacity range' => ['data' => $mark, 'opacity' => 101],
	'offset shape' => ['data' => $mark, 'offset' => [1]],
	'unknown key' => ['data' => $mark, 'position' => 'top'],
];

foreach ($overlayInvalid as $label => $overlay) {
	expectThrows(\InvalidArgumentException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [$overlay]]]), "overlay $label");
}

expectThrows(\InvalidArgumentException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => ['not' => []]]]), 'overlays must be a list');
expectThrows(\RuntimeException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['file' => $dir . '/missing.png']]]]), 'missing overlay file');
expectThrows(ImageInputException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['data' => '']]]]), 'empty overlay data');
expectThrows(ImageInputException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['data' => F::animatedGif()]]]]), 'animated overlay rejected by default');
expectThrows(ImageInputException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['data' => F::pngHeader(10_000, 10_000)]]]]), 'overlay pixel budget');
expectThrows(ImageCapabilityException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['data' => $heic]]]]), 'overlay decode capability');
check($image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['data' => $heic, 'opacity' => 0]]]])['o']['backend'] === 'gd', 'invisible overlay is never read and cannot force a backend');
expectThrows(\RuntimeException::class, static fn() => $image->encodeString($white, ['o' => ['format' => 'png', 'overlays' => [['file' => $dir . '/missing.png', 'opacity' => 0]]]]), 'invisible overlay specification is still validated');

// -- Metadata, determinism, independence --------------------------------------------

$jpegOut = $image->encodeString($jpeg6, ['o' => ['format' => 'jpeg']])['o']['data'];
check(!\str_contains($jpegOut, 'Exif') && !\str_contains($jpegOut, 'ICC_PROFILE'), 'metadata stripped');
check($jpegOut === $image->encodeString($jpeg6, ['o' => ['format' => 'jpeg']])['o']['data'], 'deterministic bytes');
check(\str_contains($jpegOut, "\xFF\xC2"), 'progressive jpeg per cfg');
check($image->encodeString($plain, ['o' => ['format' => 'jpeg', 'quality' => 40]])['o']['bytes'] < \strlen($jpegOut), 'quality applied');

$batch = [
	'thumb_webp' => ['format' => 'webp', 'width' => 64, 'height' => 64, 'fit' => 'cover'],
	'large' => ['format' => 'png', 'width' => 150],
	'thumb_jpeg' => ['format' => 'jpeg', 'width' => 64, 'height' => 64, 'fit' => 'cover'],
	'rotated' => ['format' => 'png', 'width' => 200, 'rotate' => 90],
];
$all = $image->encodeString($jpeg6, $batch);
check(\array_keys($all) === \array_keys($batch), 'results keep caller order');
$reversed = $image->encodeString($jpeg6, \array_reverse($batch, true));

foreach ($batch as $key => $output) {
	$alone = $image->encodeString($jpeg6, [$key => $output])[$key];
	check($alone['data'] === $all[$key]['data'] && $alone['data'] === $reversed[$key]['data'], "output '$key' independent of siblings and order");
}

$path6 = $put('o6.jpg', $jpeg6);
check($image->encode($path6, $batch) === $all, 'file and string sources produce identical results');

// -- Work counts: one decode per job, one resample per distinct geometry -----------

CountingBackend::reset();
$counting->encode($path6, $batch);
check((CountingBackend::$calls['decodeFile'] ?? 0) === 1 && !isset(CountingBackend::$calls['decodeString']), 'file source decoded once, from the file');
check((CountingBackend::$calls['render'] ?? 0) === 3, 'three distinct geometries resampled once each');
check((CountingBackend::$calls['encode'] ?? 0) === 4, 'four encodes');

CountingBackend::reset();
$counting->encodeString($jpeg6, $batch);
check((CountingBackend::$calls['decodeString'] ?? 0) === 1, 'string source decoded once');
check(!isset(CountingBackend::$calls['probeImage']), 'codec probes cached per service instance');

// -- Probes verify output semantics, not only format and geometry ----------------------

CountingBackend::reset();
CountingBackend::$dropAlpha = true;
$lossy = new Image(testApp($gdOnly), ['backends' => ['gd' => CountingBackend::class]]);
$lossyCaps = $lossy->capabilities();
check($lossyCaps['encode']['png'] === null && $lossyCaps['encode']['webp'] === null, 'encoder that loses alpha is not reported for alpha formats');
check($lossyCaps['encode']['jpeg'] === 'gd' && $lossyCaps['encode']['bmp'] === 'gd', 'formats without alpha are unaffected');
check($lossyCaps['decode']['png'] === 'gd', 'alpha loss withdraws encode support only');
expectThrows(ImageCapabilityException::class, static fn() => $lossy->encodeString($plain, ['o' => ['format' => 'png']]), 'job refuses the alpha-losing encoder instead of writing opaque png');
CountingBackend::reset();

done('service_test');

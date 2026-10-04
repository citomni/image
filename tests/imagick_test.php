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
 * Imagick backend checks and GD/Imagick parity.
 *
 * Covers: codec round-trips for every format the runtime reports, HEIC with
 * clean aperture and container rotation/mirroring (all eight orientations,
 * checked against libheif semantics), colorspace normalization, GIF/TIFF/BMP
 * output semantics, first-frame decoding, backend selection, overlays, and
 * identical public semantics on both backends.
 *
 * Skips (exit 0) when ext-imagick is not loaded.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Backend\ImagickBackend;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Service\Image;
use CitOmni\Image\Tests\Support\ImageFixtures as F;
use CitOmni\Image\Tests\Support\RestrictedBackend;
use CitOmni\Image\Util\ProbeSamples;

if (!imagickAvailable()) {
	echo "imagick_test: skipped (ext-imagick not loaded).\n";
	exit(0);
}

$imagick = new Image(testApp(['backends' => ['imagick']]));
$gd = new Image(testApp(['backends' => ['gd']]));
$both = new Image(testApp(['backends' => ['gd', 'imagick']]));

/** Encode one PNG output and decode it with GD for assertions. */
$png = static function (Image $service, string $data, array $output = [], array $options = []): array {
	$result = $service->encodeString($data, ['o' => $output + ['format' => 'png']], $options)['o'];
	return [gd($result['data']), $result];
};

/** Expected display corners per orientation for the quadrant fixture (stored TL red, TR green, BL blue, BR yellow). */
$corners = [
	1 => [F::RED, F::GREEN], 2 => [F::GREEN, F::RED], 3 => [F::YELLOW, F::BLUE], 4 => [F::BLUE, F::YELLOW],
	5 => [F::RED, F::BLUE], 6 => [F::BLUE, F::RED], 7 => [F::YELLOW, F::GREEN], 8 => [F::GREEN, F::YELLOW],
];

// -- Capabilities and round-trips -------------------------------------------------------

$caps = $imagick->capabilities();
check($caps['backends']['imagick']['available'] === true && \is_string($caps['backends']['imagick']['version']), 'imagick reported');
echo '  info: ' . $caps['backends']['imagick']['version'] . "\n";
echo '  info: imagick encode: ' . \implode(', ', \array_keys(\array_filter($caps['encode']))) . "\n";

$quadPng = F::encode(F::quadrants(65, 49), 'png');
$alpha = F::alphaPng();
$dir = tempDir();

foreach ($caps['encode'] as $format => $backend) {
	if ($backend === null) {
		continue;
	}

	$result = $imagick->encodeString($quadPng, ['o' => ['format' => $format]])['o'];
	$meta = $imagick->inspectString($result['data']);
	check($result['backend'] === 'imagick' && $meta['format'] === $format, "imagick $format output identified as $format");
	check([$meta['display_width'], $meta['display_height']] === [65, 49], "imagick $format output keeps 65x49");
	check(!\str_contains($result['data'], 'Exif'), "imagick $format output carries no EXIF");

	// File sources are read through a stream (HEIC/AVIF through memory); results must match.
	\file_put_contents("$dir/in.$format", $result['data']);
	$fromFile = $imagick->encode("$dir/in.$format", ['o' => ['format' => 'png']])['o'];
	$fromString = $imagick->encodeString($result['data'], ['o' => ['format' => 'png']])['o'];
	check($fromFile['data'] === $fromString['data'], "imagick $format file and string sources decode identically");
}

// -- HEIF alpha inspection on real encoder output -------------------------------------------

foreach (['heic', 'avif'] as $format) {
	if ($caps['encode'][$format] !== 'imagick') {
		continue;
	}

	$withAlpha = $imagick->encodeString($alpha, ['o' => ['format' => $format]])['o']['data'];
	$opaque = $imagick->encodeString($quadPng, ['o' => ['format' => $format]])['o']['data'];
	check($imagick->inspectString($withAlpha)['alpha'] === true, "$format with an alpha plane linked to the primary item reports alpha");
	check($imagick->inspectString($opaque)['alpha'] === false, "opaque $format reports no alpha");
}

// -- HEIC: clean aperture and container transforms ----------------------------------------
//
// Uses this runtime's HEIC encoder when present; otherwise the embedded sample, so
// decode-only builds (libheif without x265) still exercise the container geometry.
// Both are 65x49 quadrants with a clean aperture (coded 66x64).

$heic = match (true) {
	$caps['encode']['heic'] === 'imagick' => $imagick->encodeString(F::encode(F::quadrants(65, 49), 'png'), ['o' => ['format' => 'heic', 'quality' => 90]])['o']['data'],
	$caps['decode']['heic'] === 'imagick' => ProbeSamples::get(ImageFormat::Heic),
	default => null,
};

if ($heic === null) {
	echo "  info: heic tests skipped (no heic decoder)\n";
} else {
	echo '  info: heic tests use ' . ($caps['encode']['heic'] === 'imagick' ? 'this runtime\'s heic encoder' : 'the embedded sample (decode-only build)') . "\n";

	// [irot angle, imir mode] => resulting orientation, as libheif decodes them.
	$transforms = [
		[0, null, 1], [1, null, 8], [2, null, 3], [3, null, 6],
		[null, 0, 4], [null, 1, 2], [1, 0, 5], [1, 1, 7],
		[3, 0, 7], [3, 1, 5], [2, 0, 2], [2, 1, 4],
	];

	foreach ($transforms as [$angle, $mode, $orientation]) {
		$label = 'heic irot ' . ($angle ?? '-') . ' imir ' . ($mode ?? '-');
		$data = F::withHeifTransforms($heic, $angle, $mode);
		$meta = $imagick->inspectString($data);
		$expectedSize = $orientation >= 5 ? [49, 65] : [65, 49];
		check($meta['orientation'] === $orientation, "$label: orientation $orientation");
		check([$meta['display_width'], $meta['display_height']] === $expectedSize, "$label: display size");

		foreach ([[], ['auto_orient' => false]] as $options) {
			[$out, $result] = $png($imagick, $data, [], $options);
			[$topLeft, $topRight] = $corners[$orientation];
			check([$result['width'], $result['height']] === $expectedSize, "$label: output size");
			check(near(pixel($out, 3, 3), $topLeft, 60) && near(pixel($out, \imagesx($out) - 4, 3), $topRight, 60), "$label: corners (container transforms ignore auto_orient)");
		}
	}

	// Crop and fit work in display space of a rotated HEIC (display 49x65; red quadrant at x >= 25, y < 32).
	$rotated = F::withHeifTransforms($heic, 3, null);
	[$out, $result] = $png($imagick, $rotated, ['crop' => [26, 0, 22, 30], 'width' => 11]);
	check([$result['width'], $result['height']] === [11, 15] && near(pixel($out, 5, 7), F::RED, 60), 'heic crop + fit in display space');

	// Backend selection: HEIC input goes to imagick even when gd is preferred.
	$selected = $both->encodeString($rotated, ['o' => ['format' => 'jpeg']])['o'];
	check($selected['backend'] === 'imagick' && [$selected['width'], $selected['height']] === [49, 65], 'heic input selects imagick');
}

// -- Decode-only builds: decoders are verified without an encoder -----------------------

RestrictedBackend::$inner = ImagickBackend::class;
RestrictedBackend::$noEncode = ['heic', 'avif'];
$decodeOnly = new Image(testApp(['backends' => ['imagick']]), ['backends' => ['imagick' => RestrictedBackend::class]]);
$restricted = $decodeOnly->capabilities();

foreach (['heic', 'avif'] as $format) {
	if ($caps['decode'][$format] === 'imagick') {
		check($restricted['decode'][$format] === 'imagick' && $restricted['encode'][$format] === null, "$format: decoder verified although no encoder exists");
	}
}

if ($restricted['decode']['heic'] === 'imagick') {
	[$out, $result] = $png($decodeOnly, ProbeSamples::get(ImageFormat::Heic));
	check([$result['width'], $result['height']] === [65, 49] && near(pixel($out, 3, 3), F::RED, 60), 'decode-only build converts heic input');
	expectThrows(ImageCapabilityException::class, static fn() => $decodeOnly->encodeString($quadPng, ['o' => ['format' => 'heic']]), 'decode-only build refuses heic output');
}

RestrictedBackend::$noEncode = [];

// -- Colorspace normalization -------------------------------------------------------------

$red = new \Imagick();
$red->newImage(40, 30, new \ImagickPixel('rgb(200, 40, 60)'));
$ycbcr = clone $red;
$ycbcr->transformImageColorspace(\Imagick::COLORSPACE_YCBCR);
$ycbcr->setImageFormat('TIFF');
$cmyk = clone $red;
$cmyk->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
$cmyk->setImageFormat('JPEG');

[$out] = $png($imagick, $ycbcr->getImageBlob());
check(near(pixel($out, 5, 5), [200, 40, 60], 4), 'YCbCr input normalized to sRGB before processing');
[$out] = $png($imagick, $ycbcr->getImageBlob(), ['overlays' => [['data' => F::alphaPng(), 'anchor' => 'top-left']]]);
check(near(pixel($out, 30, 5), [200, 40, 60], 4), 'overlay composited onto normalized YCbCr base keeps base colors');
[$imagickOut] = $png($imagick, $cmyk->getImageBlob());
[$gdOut] = $png($gd, $cmyk->getImageBlob());
check(near(pixel($imagickOut, 5, 5), [200, 40, 60], 6) && near(pixel($gdOut, 5, 5), [200, 40, 60], 6), 'CMYK JPEG normalized on both backends');

// -- Output semantics that only imagick can exercise ---------------------------------------


if ($caps['encode']['gif'] === 'imagick') {
	$gradient = \imagecreatetruecolor(4, 1);
	\imagealphablending($gradient, false);
	\imagesavealpha($gradient, true);

	foreach ([110, 70, 50, 10] as $x => $gdAlpha) {
		\imagesetpixel($gradient, $x, 0, \imagecolorallocatealpha($gradient, 255, 0, 0, $gdAlpha));
	}

	$gif = gd($imagick->encodeString(F::encode($gradient, 'png'), ['o' => ['format' => 'gif']])['o']['data']);
	$transparent = static fn(int $x): bool => \imagecolorsforindex($gif, \imagecolorat($gif, $x, 0))['alpha'] === 127;
	check($transparent(0) && $transparent(1) && !$transparent(2) && !$transparent(3), 'gif output: binary transparency at 50% alpha');
	check($both->encodeString($alpha, ['o' => ['format' => 'gif']])['o']['backend'] === 'imagick', 'gif output selects imagick');
}

if ($caps['encode']['tiff'] === 'imagick') {
	$tiff = $imagick->encodeString($alpha, ['o' => ['format' => 'tiff']])['o']['data'];
	[$out] = $png($imagick, $tiff);
	check(pixel($out, 10, 10)[3] === 127 && pixel($out, 150, 10) === [255, 0, 255, 0], 'tiff keeps alpha');
}

[$imagickBmp] = [gd($imagick->encodeString($alpha, ['o' => ['format' => 'bmp', 'background' => '#00ff00']])['o']['data'])];
[$gdBmp] = [gd($gd->encodeString($alpha, ['o' => ['format' => 'bmp', 'background' => '#00ff00']])['o']['data'])];
check(pixel($imagickBmp, 10, 10) === pixel($gdBmp, 10, 10) && near(pixel($imagickBmp, 10, 10), [0, 255, 0], 0), 'bmp flattened identically on both backends');

// -- First frame ------------------------------------------------------------------------

// Reported first-frame support must match behavior on the embedded two-frame samples.
foreach ($caps['first_frame'] as $format => $backend) {
	if ($backend !== 'imagick') {
		continue;
	}

	[$out] = $png($imagick, ProbeSamples::multiFrame(ImageFormat::from($format)), [], ['multi_frame' => 'first']);
	check(near(pixel($out, 32, 24), [255, 0, 0], 40), "reported first-frame support for $format holds");
}

echo '  info: imagick first frame: ' . \implode(', ', \array_keys(\array_filter($caps['first_frame']))) . "\n";


[$out] = $png($imagick, F::animatedGif(), [], ['multi_frame' => 'first']);
check(near(pixel($out, 1, 1), F::RED, 0), 'imagick reads the first gif frame');

$pages = new \Imagick();

foreach (['rgb(0, 0, 255)', 'rgb(0, 255, 0)'] as $color) {
	$page = new \Imagick();
	$page->newImage(30, 20, new \ImagickPixel($color));
	$page->setImageFormat('TIFF');
	$pages->addImage($page);
}

$pages->setFormat('TIFF');
$multiPage = $pages->getImagesBlob();
check($imagick->inspectString($multiPage)['multi_frame'] === true, 'multi-page tiff detected');
[$out] = $png($imagick, $multiPage, [], ['multi_frame' => 'first']);
check(near(pixel($out, 5, 5), [0, 0, 255], 0), 'first tiff page');

// -- Parity: same job, same public semantics on GD and Imagick ----------------------------

$jobs = [
	'cover' => [F::jpeg(), ['width' => 97, 'height' => 61, 'fit' => 'cover']],
	'fill' => [F::jpeg(), ['width' => 50, 'height' => 120, 'fit' => 'fill']],
	'contain upscale' => [F::jpeg(), ['width' => 400, 'upscale' => true]],
	'crop rotate' => [F::jpeg(), ['rotate' => 90, 'crop' => [100, 0, 99, 150]]],
	'alpha webp' => [$alpha, ['format' => 'webp']],
	'alpha jpeg' => [$alpha, ['format' => 'jpeg', 'background' => '#123456']],
	'palette gif' => [F::transparentGif(), []],
	'watermark' => [F::encode(F::quadrants(200, 100), 'png'), ['overlays' => [['data' => $alpha, 'anchor' => 'bottom-right', 'width_percent' => 40, 'opacity' => 60, 'offset' => [-4, -4]]]]],
];

for ($orientation = 1; $orientation <= 8; $orientation++) {
	$jobs["exif $orientation"] = [F::jpeg($orientation), ['width' => 120]];
}

foreach ($jobs as $label => [$data, $output]) {
	$results = [];

	foreach (['gd' => $gd, 'imagick' => $imagick] as $name => $service) {
		$result = $service->encodeString($data, ['o' => $output + ['format' => 'png']])['o'];
		$results[$name] = [$result, gd($result['data'])];
	}

	[[$gdResult, $gdImage], [$imResult, $imImage]] = [$results['gd'], $results['imagick']];
	check([$gdResult['width'], $gdResult['height'], $gdResult['format']] === [$imResult['width'], $imResult['height'], $imResult['format']], "parity $label: geometry and format");

	// Sample a grid away from edges (resampling filters differ at boundaries).
	$mismatch = 0;
	$width = $gdResult['width'];
	$height = $gdResult['height'];

	for ($y = (int)($height * 0.1); $y < $height * 0.9; $y += \max(1, \intdiv($height, 9))) {
		for ($x = (int)($width * 0.1); $x < $width * 0.9; $x += \max(1, \intdiv($width, 9))) {
			$a = pixel($gdImage, $x, $y);
			$b = pixel($imImage, $x, $y);
			$alphaMatches = ($a[3] === 127) === ($b[3] === 127) && \abs($a[3] - $b[3]) <= 6;

			if (!$alphaMatches || ($a[3] < 127 && !near($a, $b, 60))) {
				$mismatch++;
			}
		}
	}

	check($mismatch <= 2, "parity $label: pixels and alpha agree ($mismatch mismatching samples)");
}

// -- No native Imagick exception crosses the backend seam --------------------------------

$backend = new ImagickBackend();
$dead = $backend->decodeString(F::encode(F::quadrants(20, 10), 'png'), ImageFormat::Png);
$live = $backend->decodeString(F::encode(F::quadrants(20, 10), 'png'), ImageFormat::Png);
$backend->release($dead);
$deadDir = tempDir();
$calls = [
	'size' => static fn() => $backend->size($dead),
	'render' => static fn() => $backend->render($dead, [0, 0, 10, 10, 5, 5]),
	'orient' => static fn() => $backend->orient($dead, 6, false),
	'fade' => static fn() => $backend->fade($dead, 50),
	'composite' => static fn() => $backend->composite($dead, $live, 0, 0, false),
	'pixelAt' => static fn() => $backend->pixelAt($dead, 1, 1),
	'encode' => static fn() => $backend->encode($dead, ImageFormat::Png, ['compression' => 6]),
	'write' => static fn() => $backend->write($dead, ImageFormat::Png, ['compression' => 6], $deadDir . '/x.png'),
	'decode garbage' => static fn() => $backend->decodeString('not an image at all', ImageFormat::Png),
];

foreach ($calls as $label => $call) {
	try {
		$call();
		check(false, "$label on an unusable handle must fail");
	} catch (\Throwable $e) {
		check($e instanceof \RuntimeException && !$e instanceof \ImagickException, "$label: " . $e::class . ', no native Imagick exception');
		check($e->getPrevious() === null || $e->getPrevious() instanceof \ImagickException, "$label: native cause kept as previous");
	}
}

$backend->release($live);
$backend->release($live);
$backend->release($dead);
check(true, 'release() is best-effort: releasing twice never throws');

// -- AVIF: small outputs are either exact or an explicit capability failure --------------

if ($caps['encode']['avif'] === 'imagick') {
	foreach ([[8, 8], [16, 16], [17, 17], [65, 49]] as [$w, $h]) {
		try {
			$result = $imagick->encodeString(F::encode(F::quadrants($w, $h), 'png'), ['o' => ['format' => 'avif']])['o'];
			$meta = $imagick->inspectString($result['data']);
			check([$meta['display_width'], $meta['display_height']] === [$w, $h], "avif {$w}x{$h} exact");
		} catch (ImageCapabilityException $e) {
			check(\str_contains($e->getMessage(), 'fails verification'), "avif {$w}x{$h} rejected explicitly");
			echo "  info: avif {$w}x{$h} rejected by output verification on this runtime\n";
		}
	}
}

done('imagick_test');

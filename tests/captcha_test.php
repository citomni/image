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
 * Checks for the CaptchaImage service: cfg and option validation, codes,
 * PNG output, seeded determinism, glyph placement against real FreeType
 * rendering, and answer verification. Needs GD with FreeType; without it only
 * the capability failure is checked.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Boot\Registry;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Service\CaptchaImage;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

$baseline = Registry::CFG_COMMON['image']['captcha'];
$captcha = new CaptchaImage(testApp());

check((Registry::MAP_COMMON['captchaImage'] ?? null) === CaptchaImage::class, 'captchaImage service registered for HTTP and CLI');

if (!\function_exists('imagettftext')) {
	expectThrows(ImageCapabilityException::class, static fn() => $captcha->create(), 'missing FreeType fails explicitly, no bitmap-font fallback');
	echo "captcha_test: rendering skipped (GD without FreeType).\n";
	done('captcha_test');
	exit(0);
}

/** Whether every pixel of a region is black (GD crops an all-black image to nothing). */
$black = static function (\GdImage $image, int $x, int $y, int $width, int $height): bool {
	if ($width <= 0 || $height <= 0) {
		return true;
	}

	$strip = \imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);

	if (!$strip instanceof \GdImage) {
		throw new \RuntimeException('Test helper could not crop.');
	}

	return \imagecropauto($strip, \IMG_CROP_BLACK) === false;
};

/** Whether no pixel outside the box [x0, x1) x [y0, y1) is lit. */
$inside = static function (\GdImage $image, int $x0, int $y0, int $x1, int $y1) use ($black): bool {
	$width = \imagesx($image);
	$height = \imagesy($image);

	return $black($image, 0, 0, $width, $y0)
		&& $black($image, 0, $y1, $width, $height - $y1)
		&& $black($image, 0, $y0, $x0, $y1 - $y0)
		&& $black($image, $x1, $y0, $width - $x1, $y1 - $y0);
};

// -- Configuration ------------------------------------------------------------------

foreach ([
	'single-character alphabet' => ['alphabet' => 'A'],
	'duplicate in alphabet' => ['alphabet' => 'ABCA'],
	'whitespace in alphabet' => ['alphabet' => 'AB CD'],
	'control character in alphabet' => ['alphabet' => "AB\tCD"],
	'invalid UTF-8 alphabet' => ['alphabet' => "AB\xFF"],
	'length 0' => ['length' => 0],
	'length above 64' => ['length' => 65],
	'length as string' => ['length' => '5'],
	'width 0' => ['width' => 0],
	'height as float' => ['height' => 64.0],
	'named background' => ['background' => 'white'],
	'empty colors' => ['colors' => []],
	'short hex color' => ['colors' => ['#fff']],
	'colors not a list' => ['colors' => ['ink' => '#000000']],
	'empty font path' => ['fonts' => ['']],
] as $label => $override) {
	expectThrows(\UnexpectedValueException::class, static fn() => new CaptchaImage(testApp(['captcha' => $override])), 'cfg rejected: ' . $label);
}

expectThrows(\UnexpectedValueException::class, static fn() => new CaptchaImage(testApp(['max_pixels' => 50_000])), 'cfg size above image.max_pixels for the work canvas rejected');
check(new CaptchaImage(testApp(['captcha' => ['alphabet' => 'æøå']])) instanceof CaptchaImage, 'multibyte alphabet accepted');

// -- Shipped baseline ---------------------------------------------------------------

foreach ($baseline['fonts'] as $font) {
	check(\is_file($font) && \is_readable($font), 'bundled font readable: ' . \basename($font));
	$sheet = $captcha->render($baseline['alphabet'], ['fonts' => [$font], 'width' => 800, 'seed' => 1]);
	check($sheet['width'] === 800, 'every alphabet glyph has ink in ' . \basename($font));
}

// -- code() -------------------------------------------------------------------------

$code = $captcha->code();
check(\strlen($code) === 5 && \strspn($code, $baseline['alphabet']) === 5, 'code() has the baseline length and alphabet');
check(\preg_match('/^[0-9]{12}$/D', $captcha->code(['alphabet' => '0123456789', 'length' => 12])) === 1, 'code() alphabet and length options');
check($captcha->verify($code, \strtolower($code)), 'a drawn code verifies');
check($captcha->render($code, ['seed' => 9]) === $captcha->render($code, ['seed' => 9]), 'a stored code and seed render the same image again');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->code(['width' => 400]), 'code() takes no render options');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->code(['length' => 65]), 'code() validates length');

// -- create() -----------------------------------------------------------------------

$result = $captcha->create();
check(\array_keys($result) === ['code', 'data', 'format', 'mime', 'width', 'height', 'bytes'], 'create() result keys');
check([$result['format'], $result['mime'], $result['width'], $result['height']] === ['png', 'image/png', 200, 64], 'create() format and baseline size');
check($result['bytes'] === \strlen($result['data']) && $result['bytes'] < 16_384, 'create() byte count');
check(\str_starts_with($result['data'], "\x89PNG\r\n\x1A\n") && \ord($result['data'][25]) === 3, 'output is a palette PNG');
$decoded = gd($result['data']);
check([\imagesx($decoded), \imagesy($decoded)] === [200, 64], 'output decodes at the reported size');

$codes = [];

for ($i = 0; $i < 200; $i++) {
	$codes[] = $captcha->create()['code'];
}

$joined = \implode('', $codes);
check(\strlen($joined) === 1000 && \strspn($joined, $baseline['alphabet']) === 1000, 'codes have the baseline length and alphabet');
check(\count(\array_unique($codes)) >= 195, 'codes are random (' . \count(\array_unique($codes)) . ' distinct of 200)');
check(\count(\count_chars($joined, 1)) === \strlen($baseline['alphabet']), 'every alphabet character is drawn');

$digits = $captcha->create(['alphabet' => '0123456789', 'length' => 8]);
check(\preg_match('/^[0-9]{8}$/D', $digits['code']) === 1, 'alphabet and length options');
$large = $captcha->create(['width' => 400, 'height' => 128]);
check([$large['width'], $large['height']] === [400, 128] && [\imagesx(gd($large['data'])), \imagesy(gd($large['data']))] === [400, 128], 'size options (2x for high-density screens)');
check($captcha->create()['data'] !== $captcha->create()['data'], 'images differ between calls');

expectThrows(\InvalidArgumentException::class, static fn() => $captcha->create(['seed' => 1]), 'create() takes no seed: its distortion is never reproducible');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->create(['size' => 10]), 'unknown option');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->create(['length' => 0]), 'invalid length option');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->create(['colors' => '#000000']), 'colors option must be a list');

// -- render() -----------------------------------------------------------------------

$seeded = $captcha->render('K7MPX', ['seed' => 7]);
check(!isset($seeded['code']) && $seeded['format'] === 'png', 'render() returns the image only');
check($captcha->render('K7MPX', ['seed' => 7]) === $seeded, 'same seed, same image');
check((new CaptchaImage(testApp()))->render('K7MPX', ['seed' => 7]) === $seeded, 'same seed on a fresh instance, same image');
check($captcha->render('K7MPX', ['seed' => 8])['data'] !== $seeded['data'], 'another seed, another image');
check($captcha->render('K7MPX')['data'] !== $captcha->render('K7MPX')['data'], 'unseeded renders differ');
check($captcha->render('7+3', ['seed' => 1])['width'] === 200, 'developer code with symbols');
check($captcha->render('Å', ['seed' => 1])['width'] === 200, 'single multibyte glyph');

$dark = gd($captcha->render('K7MPX', ['background' => '#101418', 'colors' => ['#f5f5f5'], 'seed' => 3])['data']);
\imagepalettetotruecolor($dark);
$light = 0;

for ($y = 0; $y < 64; $y += 4) {
	for ($x = 0; $x < 200; $x += 4) {
		[$r, $g, $b] = pixel($dark, $x, $y);
		$light += (int)($r + $g + $b > 384);
	}
}

check($light > 0 && $light < 400, 'dark background with light ink');

foreach ([
	'empty code' => '',
	'whitespace in code' => 'K7 MPX',
	'control character in code' => "K7\x00MPX",
	'zero-width character in code' => "K7\u{200B}MPX",
	'invalid UTF-8 code' => "K7\xFFMPX",
] as $label => $code) {
	expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render($code), 'render() rejects: ' . $label);
}

expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render('K7MPX', ['seed' => '7']), 'seed must be an int');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render('K7MPX', ['length' => 5]), 'render() takes no length');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render('K7MPX', ['width' => 24]), 'too narrow for legible glyphs');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render('K7MPX', ['height' => 16]), 'too low for legible glyphs');
expectThrows(\InvalidArgumentException::class, static fn() => $captcha->render(\str_repeat('W', 100_000)), 'absurd code length fails before measuring glyphs');
expectThrows(\InvalidArgumentException::class, static fn() => (new CaptchaImage(testApp(['max_pixels' => 100_000])))->render('K7MPX', ['width' => 400, 'height' => 128]), 'work canvas above image.max_pixels');
expectThrows(\RuntimeException::class, static fn() => $captcha->render('K7MPX', ['fonts' => [__DIR__ . '/missing.ttf']]), 'missing font');
expectThrows(\RuntimeException::class, static fn() => $captcha->render('K7MPX', ['fonts' => [__FILE__]]), 'file that is not a font');

// -- Placement: real glyph ink stays clear of the warp margin ------------------------

// Lay out codes with wide and tall glyphs under the largest warp the service
// draws, render the glyphs with FreeType on a canvas with a 40 px border, and
// require that no lit pixel reaches the margin the warp may displace into,
// nor leaves the bounds the later passes are restricted to.
$layout = new \ReflectionMethod(CaptchaImage::class, 'layout');
$width = 400;
$height = 128;
$warp = ['ay' => $height * 0.07, 'ky' => 0.0, 'py' => 0.0, 'ax' => $width * 0.014, 'kx' => 0.0, 'px' => 0.0];
$border = 40;
$slack = 8;

foreach (['WWWWW', 'MMMMM', 'JJJJJ', '44444', 'AJYTW', 'K7MPX', 'LTLTL', 'WJ'] as $code) {
	$inMargin = [];
	$outOfBounds = [];

	for ($seed = 1; $seed <= 40; $seed++) {
		$plan = $layout->invoke($captcha, \str_split($code), $baseline['fonts'], $width, $height, $warp, new Randomizer(new Xoshiro256StarStar($seed)));
		$canvas = \imagecreatetruecolor($width + 2 * $border, $height + 2 * $border);

		foreach ($plan['glyphs'] as [$glyph, $font, $size, $angle, $x, $y]) {
			\imagettftext($canvas, $size, $angle, $x + $border, $y + $border, 0xFFFFFF, $font, $glyph);
		}

		if (!$inside($canvas, $border + (int)\ceil($warp['ax']), $border + (int)\ceil($warp['ay']), $border + $width - (int)\ceil($warp['ax']), $border + $height - (int)\ceil($warp['ay']))) {
			$inMargin[] = $seed;
		}

		if (!$inside($canvas, $border + (int)\floor($plan['left']) - $slack, $border + (int)\floor($plan['top']) - $slack, $border + (int)\ceil($plan['right']) + $slack, $border + (int)\ceil($plan['bottom']) + $slack)) {
			$outOfBounds[] = $seed;
		}
	}

	check($inMargin === [], $code . ': glyphs clear of the warp margin (failing seeds: ' . \implode(', ', $inMargin) . ')');
	check($outOfBounds === [], $code . ': glyphs inside the reported bounds (failing seeds: ' . \implode(', ', $outOfBounds) . ')');
}

// -- verify() -----------------------------------------------------------------------

check($captcha->verify('K7MPX', 'K7MPX'), 'exact answer');
check($captcha->verify('K7MPX', 'k7mpx'), 'ASCII case ignored');
check($captcha->verify('K7MPX', " k7 M\tpx\n"), 'whitespace ignored');
check($captcha->verify('K7MPX', "K7MPX\u{00A0}"), 'Unicode whitespace ignored');
check($captcha->verify('10', '10'), 'answer that differs from the drawn code');
check(!$captcha->verify('K7MPX', 'K7MPY'), 'wrong character');
check(!$captcha->verify('K7MPX', 'K7MP'), 'missing character');
check(!$captcha->verify('K7MPX', 'K7MPXX'), 'extra character');
check(!$captcha->verify('', ''), 'empty expected code never verifies');
check(!$captcha->verify(' ', ''), 'whitespace-only expected code never verifies');
check(!$captcha->verify('K7MPX', "K7MPX\xFF"), 'invalid UTF-8 answer');
check(!$captcha->verify("K7MPX\xFF", "K7MPX\xFF"), 'invalid UTF-8 expected code');

$generated = $captcha->create();
check($captcha->verify($generated['code'], \strtolower($generated['code'])), 'generated code verifies');

done('captcha_test');

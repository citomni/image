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


header('Content-Type: text/plain; charset=UTF-8');

echo 'PHP: ' . PHP_VERSION . PHP_EOL;
echo 'GD loaded: ' . (extension_loaded('gd') ? 'yes' : 'no') . PHP_EOL;

if (!extension_loaded('gd')) {
	exit;
}

echo 'GD version: ' . (gd_info()['GD Version'] ?? 'unknown') . PHP_EOL;
echo 'AVIF gd_info: ' . (!empty(gd_info()['AVIF Support']) ? 'yes' : 'no') . PHP_EOL;
echo 'AVIF decode function: ' . (function_exists('imagecreatefromavif') ? 'yes' : 'no') . PHP_EOL;
echo 'AVIF encode function: ' . (function_exists('imageavif') ? 'yes' : 'no') . PHP_EOL;

$avifTypeSupported = defined('IMG_AVIF') && ((imagetypes() & IMG_AVIF) !== 0);

echo 'IMG_AVIF support: ' . ($avifTypeSupported ? 'yes' : 'no') . PHP_EOL;

if (!function_exists('imageavif') || !function_exists('imagecreatefromstring')) {
	echo 'Real AVIF round-trip: not available' . PHP_EOL;
	exit;
}

// Create a tiny test image.
$image = imagecreatetruecolor(8, 8);

if (!$image instanceof GdImage) {
	echo 'Real AVIF round-trip: failed to create source image' . PHP_EOL;
	exit;
}

$background = imagecolorallocate($image, 120, 60, 200);
imagefill($image, 0, 0, $background);

// Encode AVIF directly into memory.
ob_start();
$encodeOk = imageavif($image, null, 80);
$avifData = ob_get_clean();

echo 'Real AVIF encode: ' . ($encodeOk && is_string($avifData) && $avifData !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'Encoded bytes: ' . (is_string($avifData) ? strlen($avifData) : 0) . PHP_EOL;

if (!$encodeOk || !is_string($avifData) || $avifData === '') {
	echo 'Real AVIF decode: not tested' . PHP_EOL;
	exit;
}

// Decode the AVIF bytes again.
$decoded = @imagecreatefromstring($avifData);

echo 'Real AVIF decode: ' . ($decoded instanceof GdImage ? 'yes' : 'no') . PHP_EOL;

if ($decoded instanceof GdImage) {
	echo 'Decoded dimensions: ' . imagesx($decoded) . 'x' . imagesy($decoded) . PHP_EOL;
}

echo PHP_EOL;
echo 'gd_info()' . PHP_EOL;
print_r(gd_info());

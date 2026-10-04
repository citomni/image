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

/**
 * Probe Imagick/ImageMagick AVIF encode/decode behavior across image dimensions.
 *
 * Behavior:
 * - Creates synthetic source images entirely in memory.
 * - Encodes each source as AVIF.
 * - Decodes the resulting AVIF blob again.
 * - Reports source, encoded-container and decoded dimensions.
 * - Reports page geometry and frame count to expose padding/container quirks.
 *
 * Notes:
 * - No files are written.
 * - Pixel equality is intentionally not tested because AVIF is normally lossy.
 */

echo "Imagick AVIF capability probe\n";
echo str_repeat('=', 72) . PHP_EOL;

echo 'PHP: ' . PHP_VERSION . PHP_EOL;
echo 'Imagick extension: ' . (extension_loaded('imagick') ? 'yes' : 'no') . PHP_EOL;
echo 'Imagick class: ' . (class_exists(\Imagick::class) ? 'yes' : 'no') . PHP_EOL;

if (!class_exists(\Imagick::class)) {
	exit;
}

$version = \Imagick::getVersion();

echo 'ImageMagick: ' . ($version['versionString'] ?? 'unknown') . PHP_EOL;
echo 'AVIF listed: ' . (\Imagick::queryFormats('AVIF') !== [] ? 'yes' : 'no') . PHP_EOL;
echo PHP_EOL;

if (\Imagick::queryFormats('AVIF') === []) {
	echo "AVIF is not exposed by this ImageMagick build.\n";
	exit;
}

$tests = [
	[8, 8],
	[16, 16],
	[17, 17],
	[64, 48],
	[65, 49],
	[257, 193],
	[640, 480],
];

foreach ($tests as [$width, $height]) {
	echo str_repeat('-', 72) . PHP_EOL;
	echo "TEST {$width}x{$height}" . PHP_EOL;

	try {
		$source = new \Imagick();
		$source->newImage(
			$width,
			$height,
			new \ImagickPixel('rgb(180, 60, 120)')
		);

		$source->setImageFormat('AVIF');
		$source->setImageCompressionQuality(90);

		// Add a second color region so the source is not completely uniform.
		$draw = new \ImagickDraw();
		$draw->setFillColor(new \ImagickPixel('rgb(40, 170, 210)'));
		$draw->rectangle(
			0,
			0,
			max(0, intdiv($width, 2) - 1),
			max(0, $height - 1)
		);
		$source->drawImage($draw);

		echo 'Source dimensions: '
			. $source->getImageWidth()
			. 'x'
			. $source->getImageHeight()
			. PHP_EOL;

		echo 'Source page: '
			. json_encode($source->getImagePage(), JSON_UNESCAPED_SLASHES)
			. PHP_EOL;

		$blob = $source->getImageBlob();

		echo 'Encode: ' . ($blob !== '' ? 'yes' : 'no') . PHP_EOL;
		echo 'Encoded bytes: ' . strlen($blob) . PHP_EOL;

		if ($blob === '') {
			echo "Decode: not tested\n";
			continue;
		}

		/*
		 * First inspect the AVIF container without performing a full pixel decode.
		 */
		$ping = new \Imagick();
		$ping->pingImageBlob($blob);

		echo 'Ping format: ' . $ping->getImageFormat() . PHP_EOL;
		echo 'Ping dimensions: '
			. $ping->getImageWidth()
			. 'x'
			. $ping->getImageHeight()
			. PHP_EOL;

		echo 'Ping page: '
			. json_encode($ping->getImagePage(), JSON_UNESCAPED_SLASHES)
			. PHP_EOL;

		echo 'Ping frames: ' . $ping->getNumberImages() . PHP_EOL;

		/*
		 * Now perform a real decode.
		 */
		$decoded = new \Imagick();
		$decoded->readImageBlob($blob);

		$decodedWidth = $decoded->getImageWidth();
		$decodedHeight = $decoded->getImageHeight();

		echo 'Decode: yes' . PHP_EOL;
		echo 'Decoded format: ' . $decoded->getImageFormat() . PHP_EOL;
		echo 'Decoded dimensions: '
			. $decodedWidth
			. 'x'
			. $decodedHeight
			. PHP_EOL;

		echo 'Decoded page: '
			. json_encode($decoded->getImagePage(), JSON_UNESCAPED_SLASHES)
			. PHP_EOL;

		echo 'Decoded frames: ' . $decoded->getNumberImages() . PHP_EOL;
		echo 'Decoded colorspace: ' . $decoded->getImageColorspace() . PHP_EOL;
		echo 'Decoded alpha: '
			. ($decoded->getImageAlphaChannel() ? 'yes' : 'no')
			. PHP_EOL;

		$dimensionsMatch = $decodedWidth === $width
			&& $decodedHeight === $height;

		echo 'Dimension match: '
			. ($dimensionsMatch ? 'YES' : 'NO')
			. PHP_EOL;

		if (!$dimensionsMatch) {
			echo 'Difference: '
				. ($decodedWidth - $width)
				. ' width, '
				. ($decodedHeight - $height)
				. ' height'
				. PHP_EOL;
		}
	} catch (\Throwable $e) {
		echo 'FAILED: ' . $e::class . PHP_EOL;
		echo 'Message: ' . $e->getMessage() . PHP_EOL;
	}

	echo PHP_EOL;
}

echo str_repeat('=', 72) . PHP_EOL;
echo "Probe completed.\n";
echo "Delete this file from public web space after use.\n";

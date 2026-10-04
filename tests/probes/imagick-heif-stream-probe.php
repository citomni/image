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


if (\PHP_SAPI !== 'cli' && !\headers_sent()) {
	\header('Content-Type: text/plain; charset=UTF-8');
}

/**
 * Probe whether this ImageMagick decodes HEIC/AVIF through a PHP stream.
 *
 * Purpose:
 * - ImagickBackend reads HEIC/AVIF files into PHP memory first, because the
 *   ImageMagick 6 HEIF coder ignores the stream and opens the file by name.
 *   This probe answers whether the current runtime could decode from the
 *   stream instead, which would avoid that copy.
 *
 * Behavior:
 * - Uses the package's embedded 65x49 HEIC/AVIF samples (Util/ProbeSamples).
 * - Runs from an empty working directory, so a coder that opens the forced
 *   filename "image" by path cannot accidentally find a file.
 * - Compares three reads per format:
 *   1) blob: readImageBlob(), the current ImagickBackend path
 *   2) stream: readImageFile() on an open handle, with the forced coder
 *   3) result: whether the stream read matches the blob read
 *
 * Notes:
 * - Run with CLI (php tests/probes/imagick-heif-stream-probe.php) or through
 *   the web server. Writes only to a fresh directory under sys_get_temp_dir()
 *   and removes it afterwards. Delete from public web space after use.
 */

require __DIR__ . '/../../src/Enum/ImageFormat.php';
require __DIR__ . '/../../src/Util/ProbeSamples.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Util\ProbeSamples;

echo "Imagick HEIF stream-decode probe\n";
echo \str_repeat('=', 72) . "\n";
echo 'PHP: ' . \PHP_VERSION . ' (' . \PHP_SAPI . ")\n";

if (!\class_exists(\Imagick::class)) {
	echo "Imagick: not loaded\n";
	exit;
}

echo 'ImageMagick: ' . (\Imagick::getVersion()['versionString'] ?? 'unknown') . "\n";
echo 'Imagick extension: ' . \phpversion('imagick') . "\n\n";

$previous = \getcwd();
$work = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'citomni-heif-probe-' . \bin2hex(\random_bytes(4));
\mkdir($work);
\chdir($work);

/** Read one image and describe it: size, frame count, and the pixel at (10, 24). */
$describe = static function (\Closure $read): array {
	$image = new \Imagick();

	try {
		$read($image);
		$color = $image->getImagePixelColor(10, 24)->getColor();

		return ['ok' => true, 'text' => \sprintf('%dx%d, pixel(10,24)=%d,%d,%d', $image->getImageWidth(), $image->getImageHeight(), $color['r'], $color['g'], $color['b'])];
	} catch (\Throwable $e) {
		return ['ok' => false, 'text' => 'FAIL ' . $e::class . ': ' . \trim(\substr($e->getMessage(), 0, 140))];
	} finally {
		$image->clear();
	}
};

$verdicts = [];

try {
	foreach ([ImageFormat::Heic, ImageFormat::Avif] as $format) {
		$magick = \strtoupper($format->value);
		echo $magick . "\n" . \str_repeat('-', 72) . "\n";

		if (\Imagick::queryFormats($magick) === []) {
			echo "  not listed by this ImageMagick build\n\n";
			$verdicts[$magick] = 'not listed';
			continue;
		}

		$data = (string)ProbeSamples::get($format);
		$path = $work . \DIRECTORY_SEPARATOR . 'sample.' . $format->value;
		\file_put_contents($path, $data);

		$blob = $describe(static function (\Imagick $image) use ($magick, $data): void {
			$image->setFilename($magick . ':image[0]');
			$image->readImageBlob($data);
		});

		$stream = $describe(static function (\Imagick $image) use ($magick, $path): void {
			$handle = \fopen($path, 'rb');
			$image->setFilename($magick . ':image[0]');

			try {
				$image->readImageFile($handle);
			} finally {
				\fclose($handle);
			}
		});

		echo '  blob   (current path): ' . $blob['text'] . "\n";
		echo '  stream (candidate):    ' . $stream['text'] . "\n";

		$verdicts[$magick] = match (true) {
			!$blob['ok'] => 'no decoder (blob read fails too)',
			!$stream['ok'] => 'NO: stream read fails; memory read is required',
			$stream['text'] !== $blob['text'] => 'NO: stream read differs from blob read',
			default => 'YES: stream read works and matches the blob read',
		};

		echo "\n";
	}
} finally {
	\chdir($previous);

	foreach (\glob($work . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
		@\unlink($file);
	}

	@\rmdir($work);
}

echo "Verdict\n" . \str_repeat('-', 72) . "\n";

foreach ($verdicts as $magick => $verdict) {
	echo \str_pad($magick . ':', 7) . $verdict . "\n";
}

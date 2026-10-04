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
 * CitOmni image capability probe.
 *
 * Purpose:
 * - Inspect the image-processing capabilities exposed by the current PHP/runtime.
 * - Probe GD, Imagick/ImageMagick, GraphicsMagick/Gmagick and native libvips.
 * - Report relevant raster/vector codecs, EXIF/ICC-related support and common CLI tools.
 * - Run small in-memory round-trip tests where doing so is safe and meaningful.
 *
 * Notes:
 * - This script is read-only. It does not modify configuration or write persistent files.
 * - Native CLI probing requires shell_exec() and a command available through PATH.
 * - Native libvips probing can optionally fall back to FFI when FFI is enabled at runtime.
 * - Delete this script from public web space after use because it exposes runtime details.
 */

if (PHP_SAPI !== 'cli' && !headers_sent()) {
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
}

const PROBE_LINE_WIDTH = 78;

/**
 * Print a section heading.
 */
function section(string $title): void {
	echo PHP_EOL;
	echo str_repeat('=', PROBE_LINE_WIDTH) . PHP_EOL;
	echo $title . PHP_EOL;
	echo str_repeat('=', PROBE_LINE_WIDTH) . PHP_EOL;
}

/**
 * Print a key/value line.
 */
function item(string $label, mixed $value): void {
	if (is_bool($value)) {
		$value = $value ? 'yes' : 'no';
	} elseif ($value === null) {
		$value = 'unknown';
	} elseif (is_array($value)) {
		$value = $value === [] ? '(none)' : implode(', ', array_map('strval', $value));
	}

	echo str_pad($label, 34) . ': ' . (string)$value . PHP_EOL;
}

/**
 * Return a normalized yes/no/unknown string.
 */
function flag(?bool $value): string {
	return match ($value) {
		true => 'yes',
		false => 'no',
		default => 'unknown',
	};
}

/**
 * Return disabled PHP functions as a lookup map.
 *
 * @return array<string,true>
 */
function disabledFunctions(): array {
	$out = [];
	foreach (explode(',', (string)ini_get('disable_functions')) as $name) {
		$name = strtolower(trim($name));
		if ($name !== '') {
			$out[$name] = true;
		}
	}
	return $out;
}

/**
 * Check whether a PHP function can be called in the current runtime.
 */
function callableFunction(string $name): bool {
	static $disabled = null;
	$disabled ??= disabledFunctions();

	return function_exists($name) && !isset($disabled[strtolower($name)]);
}

/**
 * Run a fixed diagnostic shell command and return trimmed output.
 *
 * This function is only called with commands assembled from hard-coded probe data.
 */
function runCommand(string $command): ?string {
	if (!callableFunction('shell_exec')) {
		return null;
	}

	$output = @shell_exec($command . ' 2>&1');
	if (!is_string($output)) {
		return null;
	}

	$output = trim($output);
	return $output !== '' ? $output : null;
}

/**
 * Locate one native command through PATH.
 */
function commandPath(string $name): ?string {
	if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
		return null;
	}

	if (PHP_OS_FAMILY === 'Windows') {
		$output = runCommand('where.exe ' . escapeshellarg($name));
	} else {
		$output = runCommand('command -v ' . escapeshellarg($name));
	}

	if ($output === null) {
		return null;
	}

	$first = trim((string)strtok($output, "\r\n"));
	return $first !== '' ? $first : null;
}

/**
 * Run a native executable that was resolved through PATH.
 */
function runExecutable(string $path, string $arguments): ?string {
	return runCommand(escapeshellarg($path) . ' ' . $arguments);
}

/**
 * Return the first line of text.
 */
function firstLine(?string $text): ?string {
	if ($text === null || $text === '') {
		return null;
	}

	$line = strtok($text, "\r\n");
	return is_string($line) && $line !== '' ? trim($line) : null;
}

/**
 * Parse one boolean-like ImageMagick/libvips build hint.
 */
function containsWord(string $haystack, string $needle): bool {
	return preg_match('/(?:^|[^A-Za-z0-9_])' . preg_quote($needle, '/') . '(?:$|[^A-Za-z0-9_])/i', $haystack) === 1;
}

/**
 * Capture image encoder output written to the PHP output buffer.
 *
 * @return array{ok:bool,bytes:int,error:?string,data:?string}
 */
function captureEncoder(callable $callback): array {
	ob_start();
	try {
		$ok = (bool)$callback();
		$data = ob_get_clean();
		if (!is_string($data)) {
			$data = '';
		}
		return [
			'ok' => $ok && $data !== '',
			'bytes' => strlen($data),
			'error' => null,
			'data' => $data,
		];
	} catch (Throwable $e) {
		ob_end_clean();
		return [
			'ok' => false,
			'bytes' => 0,
			'error' => get_class($e) . ' - ' . $e->getMessage(),
			'data' => null,
		];
	}
}

/**
 * Create a tiny deterministic truecolor image for codec tests.
 */
function createGdProbeImage(): ?GdImage {
	if (!function_exists('imagecreatetruecolor')) {
		return null;
	}

	$image = imagecreatetruecolor(8, 8);
	if (!$image instanceof GdImage) {
		return null;
	}

	imagealphablending($image, false);
	imagesavealpha($image, true);

	$background = imagecolorallocatealpha($image, 120, 60, 200, 0);
	$accent = imagecolorallocatealpha($image, 20, 210, 80, 48);
	imagefill($image, 0, 0, $background);
	imagefilledrectangle($image, 2, 2, 5, 5, $accent);

	return $image;
}

/**
 * Run an in-memory GD encode/decode test.
 *
 * @return array{encode:string,decode:string,bytes:int,error:?string}
 */
function gdRoundTrip(string $format, GdImage $image): array {
	$result = ['encode' => 'no', 'decode' => 'not tested', 'bytes' => 0, 'error' => null];

	$encoded = match ($format) {
		'JPEG' => function_exists('imagejpeg')
			? captureEncoder(static fn(): bool => imagejpeg($image, null, 82))
			: null,
		'PNG' => function_exists('imagepng')
			? captureEncoder(static fn(): bool => imagepng($image, null, 6))
			: null,
		'GIF' => function_exists('imagegif')
			? captureEncoder(static fn(): bool => imagegif($image))
			: null,
		'WEBP' => function_exists('imagewebp')
			? captureEncoder(static fn(): bool => imagewebp($image, null, 82))
			: null,
		'AVIF' => function_exists('imageavif')
			? captureEncoder(static fn(): bool => imageavif($image, null, 82, 6))
			: null,
		'BMP' => function_exists('imagebmp')
			? captureEncoder(static fn(): bool => imagebmp($image))
			: null,
		default => null,
	};

	if ($encoded === null) {
		return $result;
	}

	$result['encode'] = $encoded['ok'] ? 'yes' : 'no';
	$result['bytes'] = $encoded['bytes'];
	$result['error'] = $encoded['error'];

	if (!$encoded['ok'] || !is_string($encoded['data']) || !function_exists('imagecreatefromstring')) {
		return $result;
	}

	try {
		$decoded = @imagecreatefromstring($encoded['data']);
		if ($decoded instanceof GdImage) {
			$result['decode'] = imagesx($decoded) === 8 && imagesy($decoded) === 8 ? 'yes' : 'unexpected dimensions';
			unset($decoded);
		} else {
			$result['decode'] = 'no';
		}
	} catch (Throwable $e) {
		$result['decode'] = 'no';
		$result['error'] = get_class($e) . ' - ' . $e->getMessage();
	}

	return $result;
}

/**
 * Check whether Imagick lists one format.
 */
function imagickListsFormat(string $format): ?bool {
	if (!class_exists(Imagick::class) || !method_exists(Imagick::class, 'queryFormats')) {
		return null;
	}

	try {
		return Imagick::queryFormats($format) !== [];
	} catch (Throwable) {
		return false;
	}
}

/**
 * Run a small Imagick encode/decode round-trip.
 *
 * @return array{listed:string,encode:string,decode:string,bytes:int,error:?string}
 */
function imagickRoundTrip(string $format): array {
	$result = [
		'listed' => flag(imagickListsFormat($format)),
		'encode' => 'not tested',
		'decode' => 'not tested',
		'bytes' => 0,
		'error' => null,
	];

	if (!class_exists(Imagick::class) || $result['listed'] !== 'yes') {
		return $result;
	}

	try {
		$image = new Imagick();
		$image->newImage(8, 8, new ImagickPixel('rgb(120,60,200)'));
		$image->setImageFormat($format);

		if (in_array($format, ['JPEG', 'WEBP', 'AVIF', 'HEIC', 'HEIF', 'JXL'], true)) {
			$image->setImageCompressionQuality(82);
		}

		$blob = $image->getImageBlob();
		$result['encode'] = $blob !== '' ? 'yes' : 'no';
		$result['bytes'] = strlen($blob);
		$image->clear();

		if ($blob !== '') {
			$decoded = new Imagick();
			$decoded->readImageBlob($blob);
			$result['decode'] = $decoded->getImageWidth() === 8 && $decoded->getImageHeight() === 8
				? 'yes'
				: 'unexpected dimensions';
			$decoded->clear();
		}
	} catch (Throwable $e) {
		if ($result['encode'] === 'not tested') {
			$result['encode'] = 'no';
		} elseif ($result['decode'] === 'not tested') {
			$result['decode'] = 'no';
		}
		$result['error'] = get_class($e) . ' - ' . $e->getMessage();
	}

	return $result;
}

/**
 * Check whether ImageMagick CLI format output lists one format.
 */
function imageMagickCliFormat(?string $list, string $format): ?bool {
	if ($list === null) {
		return null;
	}

	return preg_match('/^\s*' . preg_quote($format, '/') . '\*?\s+/mi', $list) === 1;
}

/**
 * Check whether one libvips operation is listed.
 */
function vipsOperation(?string $operations, string $nickname): ?bool {
	if ($operations === null) {
		return null;
	}

	return preg_match('/\(' . preg_quote($nickname, '/') . '\)/i', $operations) === 1
		|| preg_match('/\b' . preg_quote($nickname, '/') . '\b/i', $operations) === 1;
}

/**
 * Check whether FFI::cdef is usable in this runtime.
 */
function ffiRuntimeUsable(): bool {
	if (!extension_loaded('ffi') || !class_exists(FFI::class)) {
		return false;
	}

	$value = strtolower(trim((string)ini_get('ffi.enable')));
	return in_array($value, ['1', 'on', 'true', 'yes'], true);
}

/**
 * Probe native libvips directly through FFI using common library names.
 *
 * @return array{found:bool,library:?string,version:?string,error:?string}
 */
function probeVipsViaFfi(): array {
	$result = ['found' => false, 'library' => null, 'version' => null, 'error' => null];

	if (!ffiRuntimeUsable()) {
		return $result;
	}

	$candidates = match (PHP_OS_FAMILY) {
		'Windows' => ['libvips-42.dll', 'libvips.dll'],
		'Darwin' => ['libvips.42.dylib', 'libvips.dylib'],
		default => ['libvips.so.42', 'libvips.so'],
	};

	$lastError = null;
	foreach ($candidates as $library) {
		try {
			$ffi = FFI::cdef('const char *vips_version_string(void);', $library);
			$pointer = $ffi->vips_version_string();
			$version = FFI::string($pointer);
			return [
				'found' => true,
				'library' => $library,
				'version' => $version,
				'error' => null,
			];
		} catch (Throwable $e) {
			$lastError = $e->getMessage();
		}
	}

	$result['error'] = $lastError;
	return $result;
}

/**
 * Print one compact codec row.
 */
function codecRow(string $name, array $parts): void {
	$text = [];
	foreach ($parts as $key => $value) {
		$text[] = $key . '=' . (string)$value;
	}
	item($name, implode('  ', $text));
}

// -----------------------------------------------------------------------------
// Runtime
// -----------------------------------------------------------------------------

section('Runtime');

item('PHP version', PHP_VERSION);
item('PHP SAPI', PHP_SAPI);
item('Operating system', PHP_OS_FAMILY . ' / ' . PHP_OS);
item('Architecture', PHP_INT_SIZE === 8 ? '64-bit' : '32-bit');
item('memory_limit', ini_get('memory_limit'));
item('max_execution_time', ini_get('max_execution_time'));
item('upload_max_filesize', ini_get('upload_max_filesize'));
item('post_max_size', ini_get('post_max_size'));
item('Temp directory writable', is_writable(sys_get_temp_dir()));
item('shell_exec available', callableFunction('shell_exec'));

$disabled = array_keys(disabledFunctions());
item('Disabled PHP functions', $disabled === [] ? '(none)' : implode(', ', $disabled));

// -----------------------------------------------------------------------------
// Relevant PHP extensions
// -----------------------------------------------------------------------------

section('Relevant PHP extensions');

$extensions = [
	'gd',
	'imagick',
	'gmagick',
	'exif',
	'fileinfo',
	'mbstring',
	'ffi',
];

foreach ($extensions as $extension) {
	$loaded = extension_loaded($extension);
	$version = $loaded ? phpversion($extension) : false;
	item($extension, $loaded ? 'yes' . ($version !== false ? ' (' . $version . ')' : '') : 'no');
}

item('getimagesize()', function_exists('getimagesize'));
item('getimagesizefromstring()', function_exists('getimagesizefromstring'));
item('exif_imagetype()', function_exists('exif_imagetype'));
item('exif_read_data()', function_exists('exif_read_data'));
item('finfo class', class_exists(finfo::class));
item('FFI runtime usable', ffiRuntimeUsable());
item('ffi.enable', ini_get('ffi.enable') ?: '(empty)');

// -----------------------------------------------------------------------------
// GD
// -----------------------------------------------------------------------------

section('GD');

$gdLoaded = extension_loaded('gd');
item('GD extension', $gdLoaded);

if ($gdLoaded) {
	$gdInfo = function_exists('gd_info') ? gd_info() : [];

	item('GD version', $gdInfo['GD Version'] ?? (defined('GD_VERSION') ? GD_VERSION : 'unknown'));
	item('Bundled GD', defined('GD_BUNDLED') ? (bool)GD_BUNDLED : null);
	item('FreeType support', $gdInfo['FreeType Support'] ?? null);
	item('FreeType linkage', $gdInfo['FreeType Linkage'] ?? null);
	item('TrueType text API', function_exists('imagettftext') && function_exists('imagettfbbox'));
	item('imagecrop()', function_exists('imagecrop'));
	item('imagerotate()', function_exists('imagerotate'));
	item('imageflip()', function_exists('imageflip'));
	item('imagescale()', function_exists('imagescale'));
	item('imageaffine()', function_exists('imageaffine'));
	item('imagefilter()', function_exists('imagefilter'));

	$gdTypeMask = function_exists('imagetypes') ? imagetypes() : 0;
	item('imagetypes() mask', $gdTypeMask);

	$gdFormats = [
		'JPEG' => ['read' => 'imagecreatefromjpeg', 'write' => 'imagejpeg', 'constant' => 'IMG_JPG', 'info' => 'JPEG Support'],
		'PNG' => ['read' => 'imagecreatefrompng', 'write' => 'imagepng', 'constant' => 'IMG_PNG', 'info' => 'PNG Support'],
		'GIF' => ['read' => 'imagecreatefromgif', 'write' => 'imagegif', 'constant' => 'IMG_GIF', 'info' => 'GIF Read Support'],
		'WEBP' => ['read' => 'imagecreatefromwebp', 'write' => 'imagewebp', 'constant' => 'IMG_WEBP', 'info' => 'WebP Support'],
		'AVIF' => ['read' => 'imagecreatefromavif', 'write' => 'imageavif', 'constant' => 'IMG_AVIF', 'info' => 'AVIF Support'],
		'BMP' => ['read' => 'imagecreatefrombmp', 'write' => 'imagebmp', 'constant' => 'IMG_BMP', 'info' => 'BMP Support'],
		'WBMP' => ['read' => 'imagecreatefromwbmp', 'write' => 'imagewbmp', 'constant' => 'IMG_WBMP', 'info' => 'WBMP Support'],
		'XBM' => ['read' => 'imagecreatefromxbm', 'write' => 'imagexbm', 'constant' => 'IMG_XBM', 'info' => 'XBM Support'],
		'XPM' => ['read' => 'imagecreatefromxpm', 'write' => null, 'constant' => 'IMG_XPM', 'info' => 'XPM Support'],
		'TGA' => ['read' => 'imagecreatefromtga', 'write' => null, 'constant' => 'IMG_TGA', 'info' => 'TGA Read Support'],
	];

	echo PHP_EOL . 'Declared GD codec capabilities' . PHP_EOL;
	foreach ($gdFormats as $format => $spec) {
		$bit = defined($spec['constant']) ? constant($spec['constant']) : null;
		$mask = $bit !== null ? (($gdTypeMask & $bit) !== 0) : null;
		$read = function_exists($spec['read']);
		$write = $spec['write'] !== null ? function_exists($spec['write']) : null;
		$reported = array_key_exists($spec['info'], $gdInfo) ? (bool)$gdInfo[$spec['info']] : null;

		codecRow($format, [
			'read_fn' => flag($read),
			'write_fn' => flag($write),
			'mask' => flag($mask),
			'gd_info' => flag($reported),
		]);
	}

	$gdImage = createGdProbeImage();
	if ($gdImage instanceof GdImage) {
		echo PHP_EOL . 'Actual GD in-memory round-trip tests' . PHP_EOL;
		foreach (['JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF', 'BMP'] as $format) {
			$result = gdRoundTrip($format, $gdImage);
			codecRow($format, [
				'encode' => $result['encode'],
				'decode' => $result['decode'],
				'bytes' => $result['bytes'],
			]);
			if ($result['error'] !== null) {
				item($format . ' error', $result['error']);
			}
		}
		unset($gdImage);
	} else {
		item('GD round-trip tests', 'could not create probe image');
	}

	item('GD native ICC transform API', 'no');
} else {
	item('GD details', 'not available');
}

// -----------------------------------------------------------------------------
// Imagick / ImageMagick through PHP
// -----------------------------------------------------------------------------

section('Imagick / ImageMagick through PHP');

$imagickLoaded = extension_loaded('imagick');
$imagickClass = class_exists(Imagick::class);

item('Imagick extension', $imagickLoaded);
item('Imagick class', $imagickClass);
item('Imagick extension version', $imagickLoaded ? (phpversion('imagick') ?: 'unknown') : 'n/a');

$imagickDelegates = '';
$imagickFeatures = '';

if ($imagickClass) {
	try {
		$version = Imagick::getVersion();
		item('ImageMagick version', $version['versionString'] ?? 'unknown');
	} catch (Throwable $e) {
		item('ImageMagick version', 'error - ' . $e->getMessage());
	}

	if (method_exists(Imagick::class, 'getPackageName')) {
		try {
			item('Package name', Imagick::getPackageName());
		} catch (Throwable) {
			item('Package name', 'unknown');
		}
	}

	if (method_exists(Imagick::class, 'getReleaseDate')) {
		try {
			item('Release date', Imagick::getReleaseDate());
		} catch (Throwable) {
			item('Release date', 'unknown');
		}
	}

	if (method_exists(Imagick::class, 'getQuantumDepth')) {
		try {
			$depth = Imagick::getQuantumDepth();
			item('Quantum depth', $depth['quantumDepthString'] ?? $depth['quantumDepthLong'] ?? 'unknown');
		} catch (Throwable) {
			item('Quantum depth', 'unknown');
		}
	}

	if (method_exists(Imagick::class, 'getHDRIEnabled')) {
		try {
			item('HDRI enabled', (bool)Imagick::getHDRIEnabled());
		} catch (Throwable) {
			item('HDRI enabled', 'unknown');
		}
	}

	if (method_exists(Imagick::class, 'getFeatures')) {
		try {
			$imagickFeatures = (string)Imagick::getFeatures();
			item('Features', $imagickFeatures !== '' ? $imagickFeatures : '(none reported)');
		} catch (Throwable) {
			item('Features', 'unknown');
		}
	}

	if (method_exists(Imagick::class, 'getConfigureOptions')) {
		try {
			$options = Imagick::getConfigureOptions('*');
			$imagickDelegates = (string)($options['DELEGATES'] ?? '');
			item('Delegates', $imagickDelegates !== '' ? $imagickDelegates : '(none reported)');
			item('Configured version', $options['VERSION'] ?? $options['LIB_VERSION_NUMBER'] ?? 'unknown');
			item('Configure features', $options['FEATURES'] ?? 'unknown');
			item('LCMS/LCMS2 delegate', containsWord($imagickDelegates, 'lcms') || containsWord($imagickDelegates, 'lcms2'));
		} catch (Throwable $e) {
			item('Configure options', 'error - ' . $e->getMessage());
		}
	}

	item('ICC profile APIs', method_exists(Imagick::class, 'profileImage') && method_exists(Imagick::class, 'getImageProfiles'));
	item('Colorspace transform API', method_exists(Imagick::class, 'transformImageColorspace'));
	item('EXIF/property APIs', method_exists(Imagick::class, 'getImageProperties'));

	$imagickFormats = [
		'JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF', 'HEIC', 'HEIF', 'TIFF',
		'BMP', 'ICO', 'SVG', 'PDF', 'JP2', 'JXL', 'EXR', 'DNG', 'CR2', 'NEF',
	];

	echo PHP_EOL . 'Imagick format listing' . PHP_EOL;
	foreach ($imagickFormats as $format) {
		item($format, flag(imagickListsFormat($format)));
	}

	echo PHP_EOL . 'Actual Imagick in-memory round-trip tests' . PHP_EOL;
	foreach (['JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF', 'HEIC', 'HEIF', 'TIFF', 'JXL'] as $format) {
		$result = imagickRoundTrip($format);
		codecRow($format, [
			'listed' => $result['listed'],
			'encode' => $result['encode'],
			'decode' => $result['decode'],
			'bytes' => $result['bytes'],
		]);
		if ($result['error'] !== null) {
			item($format . ' error', $result['error']);
		}
	}
} else {
	item('Imagick details', 'not available through PHP');
}

// -----------------------------------------------------------------------------
// Gmagick / GraphicsMagick through PHP
// -----------------------------------------------------------------------------

section('Gmagick / GraphicsMagick through PHP');

$gmagickLoaded = extension_loaded('gmagick');
$gmagickClass = class_exists('Gmagick');

item('Gmagick extension', $gmagickLoaded);
item('Gmagick class', $gmagickClass);
item('Gmagick extension version', $gmagickLoaded ? (phpversion('gmagick') ?: 'unknown') : 'n/a');

if ($gmagickClass && method_exists('Gmagick', 'getversion')) {
	try {
		$version = Gmagick::getversion();
		item('GraphicsMagick version', is_array($version) ? ($version['versionString'] ?? json_encode($version)) : (string)$version);
	} catch (Throwable $e) {
		item('GraphicsMagick version', 'error - ' . $e->getMessage());
	}
}

// -----------------------------------------------------------------------------
// Native ImageMagick / GraphicsMagick CLI
// -----------------------------------------------------------------------------

section('Native ImageMagick / GraphicsMagick CLI');

if (!callableFunction('shell_exec')) {
	item('CLI probing', 'unavailable because shell_exec() cannot be called');
} else {
	$magickPath = commandPath('magick');
	$convertPath = commandPath('convert');
	$gmPath = commandPath('gm');

	item('magick executable', $magickPath ?? 'not found');
	item('convert executable', $convertPath ?? 'not found');
	item('gm executable', $gmPath ?? 'not found');

	$imageMagickCli = null;
	$imageMagickCliName = null;
	$imageMagickVersionText = null;

	if ($magickPath !== null) {
		$imageMagickCli = $magickPath;
		$imageMagickCliName = 'magick';
		$imageMagickVersionText = runExecutable($magickPath, '-version');
	} elseif ($convertPath !== null) {
		$candidateVersion = runExecutable($convertPath, '-version');
		if ($candidateVersion !== null && stripos($candidateVersion, 'ImageMagick') !== false) {
			$imageMagickCli = $convertPath;
			$imageMagickCliName = 'convert';
			$imageMagickVersionText = $candidateVersion;
		}
	}

	item('Native ImageMagick usable', $imageMagickCli !== null);
	if ($imageMagickVersionText !== null) {
		item('Native ImageMagick version', firstLine($imageMagickVersionText));
		$delegatesLine = null;
		foreach (preg_split('/\R/', $imageMagickVersionText) ?: [] as $line) {
			if (stripos($line, 'Delegates') !== false) {
				$delegatesLine = trim($line);
				break;
			}
		}
		item('Native ImageMagick delegates', $delegatesLine ?? 'not reported in version output');
	}

	if ($imageMagickCli !== null && $imageMagickCliName !== null) {
		$formatList = runExecutable($imageMagickCli, '-list format');
		if ($formatList !== null) {
			echo PHP_EOL . 'Native ImageMagick format listing' . PHP_EOL;
			foreach (['JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF', 'HEIC', 'HEIF', 'TIFF', 'SVG', 'PDF', 'JP2', 'JXL', 'EXR'] as $format) {
				item($format, flag(imageMagickCliFormat($formatList, $format)));
			}
		} else {
			item('Native format listing', 'not available');
		}
	}

	if ($gmPath !== null) {
		$gmVersion = runExecutable($gmPath, 'version');
		item('Native GraphicsMagick version', firstLine($gmVersion) ?? 'could not query');
	}
}

// -----------------------------------------------------------------------------
// Native libvips
// -----------------------------------------------------------------------------

section('Native libvips');

$vipsPath = null;
$vipsForeign = null;
$vipsConfig = null;
$vipsVersion = null;
$vipsFfi = ['found' => false, 'library' => null, 'version' => null, 'error' => null];

if (callableFunction('shell_exec')) {
	$vipsPath = commandPath('vips');
	$vipsHeaderPath = commandPath('vipsheader');
	$vipsThumbnailPath = commandPath('vipsthumbnail');

	item('vips executable', $vipsPath ?? 'not found');
	item('vipsheader executable', $vipsHeaderPath ?? 'not found');
	item('vipsthumbnail executable', $vipsThumbnailPath ?? 'not found');

	if ($vipsPath !== null) {
		$vipsVersion = runExecutable($vipsPath, '--version');
		$vipsForeign = runExecutable($vipsPath, '-l foreign');
		$vipsConfig = runExecutable($vipsPath, '--vips-config');

		item('libvips version', firstLine($vipsVersion) ?? 'unknown');

		if ($vipsConfig !== null && stripos($vipsConfig, 'Unknown option') === false) {
			item('ICC via lcms2', stripos($vipsConfig, 'ICC profile support with lcms2: true') !== false);
			item('libheif enabled', stripos($vipsConfig, 'HEIC/AVIF load/save with libheif: true') !== false);
			item('libwebp enabled', stripos($vipsConfig, 'WebP load/save with libwebp: true') !== false);
			item('libjpeg enabled', stripos($vipsConfig, 'JPEG load/save with libjpeg: true') !== false);
			item('libpng enabled', stripos($vipsConfig, 'PNG load/save with libpng: true') !== false);
			item('libtiff enabled', stripos($vipsConfig, 'TIFF load/save with libtiff') !== false);
			item('libjxl enabled', stripos($vipsConfig, 'JXL load/save with libjxl: true') !== false);
			item('libraw enabled', stripos($vipsConfig, 'RAW load with libraw') !== false);
		} else {
			item('libvips build config', 'not available through CLI');
		}

		if ($vipsForeign !== null) {
			echo PHP_EOL . 'libvips loader/saver operations' . PHP_EOL;
			$operations = [
				'JPEG' => ['jpegload', 'jpegsave'],
				'PNG' => ['pngload', 'pngsave'],
				'GIF' => ['gifload', 'gifsave'],
				'WEBP' => ['webpload', 'webpsave'],
				'HEIC/HEIF/AVIF family' => ['heifload', 'heifsave'],
				'TIFF' => ['tiffload', 'tiffsave'],
				'JXL' => ['jxlload', 'jxlsave'],
				'JP2/JPEG2000' => ['jp2kload', 'jp2ksave'],
				'SVG' => ['svgload', null],
				'PDF' => ['pdfload', null],
				'RAW' => ['rawload', null],
				'OpenEXR' => ['openexrload', null],
			];

			foreach ($operations as $format => [$load, $save]) {
				codecRow($format, [
					'load' => flag(vipsOperation($vipsForeign, $load)),
					'save' => $save !== null ? flag(vipsOperation($vipsForeign, $save)) : 'n/a',
				]);
			}
		}
	}
} else {
	item('CLI probing', 'unavailable because shell_exec() cannot be called');
}

$vipsFfi = probeVipsViaFfi();
item('FFI libvips found', $vipsFfi['found']);
if ($vipsFfi['found']) {
	item('FFI libvips library', $vipsFfi['library']);
	item('FFI libvips version', $vipsFfi['version']);
} elseif (ffiRuntimeUsable()) {
	item('FFI libvips probe error', $vipsFfi['error'] ?? 'library not found through common names');
} else {
	item('FFI libvips probe', 'not attempted because FFI runtime access is unavailable');
}

// -----------------------------------------------------------------------------
// Other native image tools
// -----------------------------------------------------------------------------

section('Other native image tools');

if (!callableFunction('shell_exec')) {
	item('Native tool probing', 'unavailable because shell_exec() cannot be called');
} else {
	$tools = [
		'exiftool' => '-ver',
		'avifenc' => '--version',
		'avifdec' => '--version',
		'heif-convert' => '--version',
		'heif-enc' => '--version',
		'cwebp' => '-version',
		'dwebp' => '-version',
		'pngquant' => '--version',
		'jpegoptim' => '--version',
		'optipng' => '-version',
		'ffmpeg' => '-version',
	];

	foreach ($tools as $tool => $versionArg) {
		$path = commandPath($tool);
		if ($path === null) {
			item($tool, 'not found');
			continue;
		}

		$version = runExecutable($path, $versionArg);
		item($tool, 'found - ' . (firstLine($version) ?? 'version unavailable'));
	}
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------

section('Summary');

$gdAvif = $gdLoaded
	&& function_exists('imagecreatefromavif')
	&& function_exists('imageavif')
	&& defined('IMG_AVIF')
	&& function_exists('imagetypes')
	&& ((imagetypes() & IMG_AVIF) !== 0);

$imagickAvif = imagickListsFormat('AVIF') === true;
$imagickHeic = imagickListsFormat('HEIC') === true || imagickListsFormat('HEIF') === true;
$vipsAvailable = $vipsPath !== null || $vipsFfi['found'];
$vipsHeif = $vipsForeign !== null ? (vipsOperation($vipsForeign, 'heifload') === true) : null;

item('GD backend usable', $gdLoaded);
item('Imagick backend usable in PHP', $imagickClass);
item('Native ImageMagick CLI usable', isset($imageMagickCli) && $imageMagickCli !== null);
item('Native libvips detectable', $vipsAvailable);
item('GD AVIF', $gdAvif);
item('Imagick AVIF listed', $imagickAvif);
item('Imagick HEIC/HEIF listed', $imagickHeic);
item('libvips HEIF-family loader', flag($vipsHeif));
item('EXIF PHP extension', extension_loaded('exif'));
item('Imagick ICC APIs', $imagickClass && method_exists(Imagick::class, 'profileImage'));
item('Imagick LCMS delegate', $imagickDelegates !== '' ? (containsWord($imagickDelegates, 'lcms') || containsWord($imagickDelegates, 'lcms2')) : null);

section('End');

echo 'Probe completed.' . PHP_EOL;
echo 'Delete this file from public web space after use.' . PHP_EOL;

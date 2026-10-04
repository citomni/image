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

namespace CitOmni\Image\Tests\Support;

use CitOmni\Image\Backend\GdBackend;
use CitOmni\Image\Backend\ImageBackend;
use CitOmni\Image\Enum\ImageFormat;

/**
 * GD backend wrapper that counts calls and can inject faults.
 *
 * Wired into the Image service through the "backends" service option.
 * State is static because the service instantiates backends itself.
 */
final class CountingBackend implements ImageBackend {

	/** @var array<string, int> */
	public static array $calls = [];

	/** @var array<int, true> Handles created and not yet released, by object id. */
	public static array $live = [];

	/** Called before each write() with the target path and the write ordinal. */
	public static ?\Closure $beforeWrite = null;

	/** When true, encode()/write() emit a 1x1 PNG regardless of the request. */
	public static bool $corruptOutput = false;

	/** When true, encode()/write() flatten onto white first: an encoder that loses alpha. */
	public static bool $dropAlpha = false;

	/** When true, PNG output gets a Display P3 iCCP chunk: sRGB pixels labeled as another space. */
	public static bool $mislabelOutput = false;

	/** When true, release() throws: a backend violating the no-throw contract. */
	public static bool $releaseThrows = false;

	private GdBackend $inner;


	public function __construct() {
		$this->inner = new GdBackend();
	}


	public static function reset(): void {
		self::$calls = [];
		self::$live = [];
		self::$beforeWrite = null;
		self::$corruptOutput = false;
		self::$dropAlpha = false;
		self::$releaseThrows = false;
		self::$mislabelOutput = false;
	}


	public static function isAvailable(): bool {
		return GdBackend::isAvailable();
	}


	public function name(): string {
		return $this->inner->name();
	}


	public function version(): ?string {
		return $this->inner->version();
	}


	public function canDecode(ImageFormat $format): bool {
		return $this->inner->canDecode($format);
	}


	public function canEncode(ImageFormat $format): bool {
		return $this->inner->canEncode($format);
	}


	public function canDecodeFirstFrame(ImageFormat $format): bool {
		return $this->inner->canDecodeFirstFrame($format);
	}


	public function canManageColor(): bool {
		return $this->inner->canManageColor();
	}


	public function appliesContainerTransforms(ImageFormat $format): bool {
		return $this->inner->appliesContainerTransforms($format);
	}


	public function probeImage(): object {
		self::count('probeImage');

		return $this->inner->probeImage();
	}


	public function pixelAt(object $image, int $x, int $y): array {
		return $this->inner->pixelAt($image, $x, $y);
	}


	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object {
		self::count('decodeFile');

		return self::track($this->inner->decodeFile($path, $format, $sourceProfile), null);
	}


	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object {
		self::count('decodeString');

		return self::track($this->inner->decodeString($data, $format, $sourceProfile), null);
	}


	public function size(object $image): array {
		return $this->inner->size($image);
	}


	public function render(object $image, array $plan): object {
		$result = $this->inner->render($image, $plan);

		if ($result !== $image) {
			self::count('render');
		}

		return self::track($result, $image);
	}


	public function orient(object $image, int $orientation, bool $owned): object {
		if ($orientation !== 1) {
			self::count('orient');
		}

		return self::track($this->inner->orient($image, $orientation, $owned), $image);
	}


	public function fade(object $image, int $opacity): object {
		self::count('fade');

		return self::track($this->inner->fade($image, $opacity), $image);
	}


	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object {
		self::count('composite');

		return self::track($this->inner->composite($base, $overlay, $x, $y, $owned), $base);
	}


	public function encode(object $image, ImageFormat $format, array $settings): string {
		self::count('encode');

		if (self::$corruptOutput) {
			return $this->inner->encode(\imagecreatetruecolor(1, 1), ImageFormat::Png, ['compression' => 6]);
		}

		$data = $this->inner->encode(self::$dropAlpha ? $this->opaque($image) : $image, $format, $settings);

		return self::$mislabelOutput && $format === ImageFormat::Png ? self::mislabel($data) : $data;
	}


	public function write(object $image, ImageFormat $format, array $settings, string $path): void {
		$ordinal = self::count('write');

		if (self::$beforeWrite !== null) {
			(self::$beforeWrite)($path, $ordinal);
		}

		if (self::$corruptOutput) {
			$this->inner->write(\imagecreatetruecolor(1, 1), ImageFormat::Png, ['compression' => 6], $path);
			return;
		}

		$this->inner->write(self::$dropAlpha ? $this->opaque($image) : $image, $format, $settings, $path);

		if (self::$mislabelOutput && $format === ImageFormat::Png) {
			\file_put_contents($path, self::mislabel((string)\file_get_contents($path)));
		}
	}


	/** Insert a Display P3 iCCP chunk after IHDR. */
	private static function mislabel(string $png): string {
		return \substr($png, 0, 33) . ImageFixtures::pngChunk('iCCP', "P3\x00\x00" . \gzcompress(\CitOmni\Image\Color\Profiles::displayP3())) . \substr($png, 33);
	}


	public function release(object $image): void {
		if (self::$releaseThrows) {
			throw new \RuntimeException('injected release failure');
		}

		unset(self::$live[\spl_object_id($image)]);
		$this->inner->release($image);
	}


	/** Record a newly created handle; an operation returning its input creates none. */
	private static function track(object $result, ?object $input): object {
		if ($result !== $input) {
			self::$live[\spl_object_id($result)] = true;
		}

		return $result;
	}


	private function opaque(object $image): object {
		[$width, $height] = $this->inner->size($image);
		$canvas = \imagecreatetruecolor($width, $height);
		\imagefill($canvas, 0, 0, \imagecolorallocate($canvas, 255, 255, 255));

		return $this->inner->composite($canvas, $image, 0, 0, true);
	}


	private static function count(string $method): int {
		return self::$calls[$method] = (self::$calls[$method] ?? 0) + 1;
	}


}

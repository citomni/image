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
 * GD backend that claims color management but ignores source profiles.
 *
 * Simulates an engine whose color delegate is listed but does nothing; the
 * Image service must detect this with its conversion probe.
 */
final class ColorIgnoringBackend implements ImageBackend {

	private GdBackend $inner;


	public function __construct() {
		$this->inner = new GdBackend();
	}


	public static function isAvailable(): bool {
		return GdBackend::isAvailable();
	}


	public function name(): string {
		return 'gd';
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
		return true;
	}


	public function appliesContainerTransforms(ImageFormat $format): bool {
		return $this->inner->appliesContainerTransforms($format);
	}


	public function probeImage(): object {
		return $this->inner->probeImage();
	}


	public function pixelAt(object $image, int $x, int $y): array {
		return $this->inner->pixelAt($image, $x, $y);
	}


	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object {
		return $this->inner->decodeFile($path, $format);
	}


	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object {
		return $this->inner->decodeString($data, $format);
	}


	public function size(object $image): array {
		return $this->inner->size($image);
	}


	public function render(object $image, array $plan): object {
		return $this->inner->render($image, $plan);
	}


	public function orient(object $image, int $orientation, bool $owned): object {
		return $this->inner->orient($image, $orientation, $owned);
	}


	public function fade(object $image, int $opacity): object {
		return $this->inner->fade($image, $opacity);
	}


	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object {
		return $this->inner->composite($base, $overlay, $x, $y, $owned);
	}


	public function encode(object $image, ImageFormat $format, array $settings): string {
		return $this->inner->encode($image, $format, $settings);
	}


	public function write(object $image, ImageFormat $format, array $settings, string $path): void {
		$this->inner->write($image, $format, $settings, $path);
	}


	public function release(object $image): void {
		$this->inner->release($image);
	}


}

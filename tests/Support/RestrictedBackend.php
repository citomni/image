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
 * Wraps a real backend and withholds selected encoders.
 *
 * Emulates decode-only builds (e.g. ImageMagick with libheif but without
 * x265) on any machine. Wired through the "backends" service option; state
 * is static because the service instantiates backends itself.
 */
final class RestrictedBackend implements ImageBackend {

	/** @var class-string<ImageBackend> */
	public static string $inner = GdBackend::class;

	/** @var list<string> Format values whose encoder is withheld. */
	public static array $noEncode = [];

	private ImageBackend $backend;


	public function __construct() {
		$this->backend = new (self::$inner)();
	}


	public static function isAvailable(): bool {
		return (self::$inner)::isAvailable();
	}


	public function name(): string {
		return $this->backend->name();
	}


	public function version(): ?string {
		return $this->backend->version();
	}


	public function canDecode(ImageFormat $format): bool {
		return $this->backend->canDecode($format);
	}


	public function canEncode(ImageFormat $format): bool {
		return !\in_array($format->value, self::$noEncode, true) && $this->backend->canEncode($format);
	}


	public function canDecodeFirstFrame(ImageFormat $format): bool {
		return $this->backend->canDecodeFirstFrame($format);
	}


	public function canManageColor(): bool {
		return $this->backend->canManageColor();
	}


	public function appliesContainerTransforms(ImageFormat $format): bool {
		return $this->backend->appliesContainerTransforms($format);
	}


	public function probeImage(): object {
		return $this->backend->probeImage();
	}


	public function pixelAt(object $image, int $x, int $y): array {
		return $this->backend->pixelAt($image, $x, $y);
	}


	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object {
		return $this->backend->decodeFile($path, $format, $sourceProfile);
	}


	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object {
		return $this->backend->decodeString($data, $format, $sourceProfile);
	}


	public function size(object $image): array {
		return $this->backend->size($image);
	}


	public function render(object $image, array $plan): object {
		return $this->backend->render($image, $plan);
	}


	public function orient(object $image, int $orientation, bool $owned): object {
		return $this->backend->orient($image, $orientation, $owned);
	}


	public function fade(object $image, int $opacity): object {
		return $this->backend->fade($image, $opacity);
	}


	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object {
		return $this->backend->composite($base, $overlay, $x, $y, $owned);
	}


	public function encode(object $image, ImageFormat $format, array $settings): string {
		return $this->backend->encode($image, $format, $settings);
	}


	public function write(object $image, ImageFormat $format, array $settings, string $path): void {
		$this->backend->write($image, $format, $settings, $path);
	}


	public function release(object $image): void {
		$this->backend->release($image);
	}


}

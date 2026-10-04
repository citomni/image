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

namespace CitOmni\Image\Backend;

use CitOmni\Image\Enum\ImageFormat;

/**
 * Internal seam between the Image service and a concrete image engine.
 *
 * @internal Not a public extension contract. Signatures may change between
 *           any two releases. Callers use the Image service only.
 *
 * Behavior:
 * - A backend works on opaque native handles (e.g. \GdImage) that never leave
 *   the package.
 * - Handles returned by decode*() and render() are normalized: alpha lives in
 *   the alpha channel only, with no hidden transparency key or palette state.
 * - Geometry is decided by the Image service. A backend executes plans; it
 *   never chooses sizes, crops, or orientation on its own.
 * - Engine-native exceptions never cross this seam. Every method reports
 *   failures as ImageInputException, ImageCapabilityException, or
 *   \RuntimeException, as documented per method; release() never throws.
 * - Codec verification is done by the Image service; a backend only supplies
 *   the probe image and pixel reads.
 *
 * Notes:
 * - Implementations must be cheap to construct; heavy work happens in methods.
 * - Encoder settings arrive resolved and validated:
 *   quality (int 0-100), compression (int 0-9), progressive (bool),
 *   speed (int 0-9), background (array{0:int, 1:int, 2:int}).
 * - Output semantics are package rules, not backend choices: 8 bits per
 *   channel, no metadata, jpeg/bmp flattened, gif with binary transparency
 *   (pixels less than 50% opaque become fully transparent).
 */
interface ImageBackend {

	public const PROBE_WIDTH = 65;
	public const PROBE_HEIGHT = 49;


	/**
	 * Whether the native engine is loaded in this runtime.
	 *
	 * @return bool True when the backend can be instantiated and used.
	 */
	public static function isAvailable(): bool;


	/**
	 * Stable backend identifier reported in results and capabilities.
	 *
	 * @return string Identifier, e.g. "gd".
	 */
	public function name(): string;


	/**
	 * Native engine version, when known.
	 *
	 * @return string|null Version string.
	 */
	public function version(): ?string;


	/**
	 * Whether the engine build declares a decoder for the format.
	 *
	 * @param ImageFormat $format Format.
	 * @return bool Declared decode support.
	 */
	public function canDecode(ImageFormat $format): bool;


	/**
	 * Whether the backend offers an encoder for the format with the package's
	 * output semantics.
	 *
	 * @param ImageFormat $format Format.
	 * @return bool Declared encode support.
	 */
	public function canEncode(ImageFormat $format): bool;


	/**
	 * Whether the backend can decode the first frame of a multi-frame source.
	 *
	 * @param ImageFormat $format Format.
	 * @return bool True when first-frame decoding is reliable.
	 */
	public function canDecodeFirstFrame(ImageFormat $format): bool;


	/**
	 * Whether the engine can convert pixel data between ICC profiles.
	 *
	 * Declared support only; the Image service verifies it with a real
	 * conversion before relying on it.
	 *
	 * @return bool True when decode*() accepts a source profile.
	 */
	public function canManageColor(): bool;


	/**
	 * Whether decoding applies HEIF container transforms (clap, irot, imir).
	 *
	 * When true, a decoded handle already shows the clean, oriented picture.
	 * When false, the handle holds the coded image and the Image service
	 * applies the transforms itself.
	 *
	 * @param ImageFormat $format Format.
	 * @return bool True when container transforms are applied on decode.
	 */
	public function appliesContainerTransforms(ImageFormat $format): bool;


	/**
	 * Create the capability probe image.
	 *
	 * Behavior:
	 * - PROBE_WIDTH x PROBE_HEIGHT, four vertical zones:
	 *   1) x 0-15: opaque rgb(200, 40, 60)
	 *   2) x 16-31: rgb(40, 170, 210) at 40% opacity
	 *   3) x 32-47: rgb(40, 170, 210) at 60% opacity
	 *   4) x 48-64: fully transparent
	 *
	 * @return object Native handle.
	 * @throws \RuntimeException When the engine fails.
	 */
	public function probeImage(): object;


	/**
	 * Read one pixel as straight (non-premultiplied) RGBA, 0-255 per channel.
	 *
	 * @param object $image Native handle.
	 * @param int $x Column.
	 * @param int $y Row.
	 * @return array{0:int, 1:int, 2:int, 3:int} Red, green, blue, opacity (255 = opaque).
	 * @throws \RuntimeException When the engine fails.
	 */
	public function pixelAt(object $image, int $x, int $y): array;


	/**
	 * Decode a file without loading it into a PHP string.
	 *
	 * Behavior:
	 * - With $sourceProfile, the pixel data is converted from that ICC profile
	 *   to sRGB (perceptual intent) before working-colorspace normalization.
	 *   Requires canManageColor().
	 *
	 * @param string $path Readable file.
	 * @param ImageFormat $format Format detected from content.
	 * @param string|null $sourceProfile ICC profile the pixel data is encoded in, or null for no conversion.
	 * @return object Normalized native handle.
	 * @throws \CitOmni\Image\Exception\ImageInputException When the data cannot be decoded, or the profile does not match the data or cannot be applied.
	 */
	public function decodeFile(string $path, ImageFormat $format, ?string $sourceProfile = null): object;


	/**
	 * Decode in-memory data.
	 *
	 * Behavior:
	 * - Same color handling as decodeFile().
	 *
	 * @param string $data Image bytes.
	 * @param ImageFormat $format Format detected from content.
	 * @param string|null $sourceProfile ICC profile the pixel data is encoded in, or null for no conversion.
	 * @return object Normalized native handle.
	 * @throws \CitOmni\Image\Exception\ImageInputException When the data cannot be decoded, or the profile does not match the data or cannot be applied.
	 */
	public function decodeString(string $data, ImageFormat $format, ?string $sourceProfile = null): object;


	/**
	 * Return the pixel size of a handle.
	 *
	 * @param object $image Native handle.
	 * @return array{0:int, 1:int} Width and height.
	 */
	public function size(object $image): array;


	/**
	 * Execute a stored-space plan: select a rectangle and resample it.
	 *
	 * @param object $image Native handle; not modified.
	 * @param array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int} $plan Source x, y, width, height; target width, height.
	 * @return object New handle, or $image itself for an identity plan.
	 * @throws \RuntimeException When the engine fails.
	 */
	public function render(object $image, array $plan): object;


	/**
	 * Apply an EXIF orientation.
	 *
	 * @param object $image Native handle.
	 * @param int $orientation EXIF orientation 1-8.
	 * @param bool $owned True when $image may be mutated in place.
	 * @return object Oriented handle (the same instance for orientation 1).
	 * @throws \RuntimeException When the engine fails.
	 */
	public function orient(object $image, int $orientation, bool $owned): object;


	/**
	 * Return a copy whose alpha is multiplied by $opacity / 100.
	 *
	 * @param object $image Native handle; not modified.
	 * @param int $opacity Opacity 1-99.
	 * @return object New faded handle.
	 * @throws \RuntimeException When the engine fails.
	 */
	public function fade(object $image, int $opacity): object;


	/**
	 * Draw an overlay onto a base image with source-over compositing.
	 *
	 * Behavior:
	 * - Pixels falling outside the base are clipped.
	 * - Both images' alpha is respected (Porter-Duff "over", straight alpha).
	 *
	 * @param object $base Native handle.
	 * @param object $overlay Native handle; not modified.
	 * @param int $x Left edge of the overlay on the base; may be negative.
	 * @param int $y Top edge of the overlay on the base; may be negative.
	 * @param bool $owned True when $base may be mutated in place.
	 * @return object Composited handle.
	 * @throws \RuntimeException When the engine fails.
	 */
	public function composite(object $base, object $overlay, int $x, int $y, bool $owned): object;


	/**
	 * Encode to bytes.
	 *
	 * @param object $image Native handle; not modified.
	 * @param ImageFormat $format Output format; canEncode() must be true.
	 * @param array<string, mixed> $settings Resolved encoder settings.
	 * @return string Encoded bytes.
	 * @throws \RuntimeException When encoding fails.
	 */
	public function encode(object $image, ImageFormat $format, array $settings): string;


	/**
	 * Encode directly to a file.
	 *
	 * @param object $image Native handle; not modified.
	 * @param ImageFormat $format Output format; canEncode() must be true.
	 * @param array<string, mixed> $settings Resolved encoder settings.
	 * @param string $path Target file; created or truncated.
	 * @return void
	 * @throws \RuntimeException When encoding or writing fails.
	 */
	public function write(object $image, ImageFormat $format, array $settings, string $path): void;


	/**
	 * Release native resources held by a handle as early as possible.
	 *
	 * Behavior:
	 * - Best-effort cleanup. Must not throw: a failing release must never
	 *   mask the job's primary failure or change its write semantics.
	 *
	 * @param object $image Native handle; unusable afterwards.
	 * @return void
	 */
	public function release(object $image): void;


}

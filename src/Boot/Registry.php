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

namespace CitOmni\Image\Boot;

/**
 * Declare this provider package's boot contributions.
 *
 * CitOmni reads these constants when composing the active HTTP or CLI app.
 * All supported contribution constants are documented below but intentionally
 * left undeclared. Uncomment only those the concrete package actually needs.
 *
 * Behavior:
 * - MAP_COMMON contributes services shared by HTTP and CLI.
 * - MAP_HTTP and MAP_CLI contribute mode-specific services.
 * - CFG_COMMON contributes configuration shared by HTTP and CLI.
 * - CFG_HTTP and CFG_CLI contribute mode-specific configuration.
 * - ROUTES_HTTP contributes HTTP dispatch entries.
 * - COMMANDS_CLI contributes CLI dispatch entries.
 *
 * Notes:
 * - Service definitions may be an FQCN string or an array containing "class"
 *   and optional "options".
 * - Within one provider, mode-specific service definitions override shared
 *   service definitions with the same service ID.
 * - Within one provider, mode-specific configuration is merged after shared
 *   configuration, so mode-specific values win on conflicting associative keys.
 * - HTTP routes and CLI commands are dispatch maps, not configuration values.
 * - Keep SQL in Repositories and transport concerns in Controllers or Commands.
 */
final class Registry {

	/**
	 * Services available in both HTTP and CLI mode.
	 *
	 * Typical entries use either a service class directly or a class/options
	 * definition. Services are resolved lazily through the App service map.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	public const array MAP_COMMON = [
		'image' => \CitOmni\Image\Service\Image::class,
		'captchaImage' => \CitOmni\Image\Service\CaptchaImage::class,
	];

	/**
	 * Services available only in HTTP mode.
	 *
	 * Use this only when a service genuinely depends on the HTTP runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_HTTP = [
	// ];

	/**
	 * Services available only in CLI mode.
	 *
	 * Use this only when a service genuinely depends on the CLI runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_CLI = [
	// ];

	/**
	 * Configuration defaults shared by HTTP and CLI mode.
	 *
	 * Keep package-owned defaults under package-specific keys. Concrete host apps
	 * may override provider configuration through the normal CitOmni config flow.
	 *
	 * @var array<string|int, mixed>
	 */
	public const array CFG_COMMON = [
		'image' => [
			// Backend preference. The first available backend that can decode every
			// input and encode every requested output handles a job. Unavailable
			// backends are skipped. Lists are replaced, not merged, by host config.
			'backends' => ['gd', 'imagick'],

			// Upper bound for width * height of any source or output. A decoded
			// truecolor image costs ~4 bytes per pixel; 25 MP is ~100 MB.
			'max_pixels' => 25_000_000,

			// Flatten color for outputs without alpha (jpeg, bmp).
			'background' => '#ffffff',

			// Color policy. "srgb": convert sources in another color space (Display
			// P3, Adobe RGB, CMYK, ...) to sRGB using their ICC profile; requires a
			// backend with color management and fails explicitly otherwise.
			// "ignore": keep pixel values as they are and discard profiles.
			'color' => 'srgb',

			'jpeg' => [
				'quality'     => 82,   // 0-100
				'progressive' => true,
			],
			'png' => [
				'compression' => 6,    // zlib level 0-9 (lossless; size vs. CPU only)
			],
			'webp' => [
				'quality' => 80,       // 0-100
			],
			'avif' => [
				'quality' => 60,       // 0-100
				'speed'   => 6,        // 0 (slowest, smallest) - 9 (fastest); honored where the encoder supports it
			],
			'heic' => [
				'quality' => 75,       // 0-100
			],

			// CaptchaImage defaults; code(), create() and render() take per-call
			// overrides. See Service\CaptchaImage.
			'captcha' => [
				// Characters a generated code is drawn from. Groups that are easy to
				// confuse once distorted are left out entirely: 0/O/Q/D, 1/I, 2/Z,
				// 5/S, 6/G, 8/B, U/V. Answers are compared case-insensitively.
				'alphabet' => 'ACEFHJKLMNPRTWXY3479',
				'length' => 5,

				// Output size in pixels. For high-density screens, render at twice
				// the size and set the <img> width and height to the 1x size.
				'width' => 200,
				'height' => 64,

				'background' => '#f1f5f8',

				// Ink colors; one is picked per image and used for the text and the
				// interference curve alike, so color cannot separate them.
				'colors' => ['#1e293b', '#0d47a1', '#7f1d1d', '#14532d', '#4a148c'],

				// TrueType/OpenType files; one is picked per glyph. Lists are
				// replaced, not merged, by host config.
				'fonts' => [
					__DIR__ . '/../../assets/fonts/Roboto-Regular.ttf',
					__DIR__ . '/../../assets/fonts/RobotoSlab-Regular.ttf',
				],
			],
		],
	];

	/**
	 * Configuration defaults used only in HTTP mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_HTTP = [
	// ];

	/**
	 * Configuration defaults used only in CLI mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_CLI = [
	// ];

	/**
	 * HTTP route dispatch entries contributed by this provider.
	 *
	 * Keep route definitions here rather than inside CFG_COMMON or CFG_HTTP.
	 * The concrete route entry contract is owned by the CitOmni HTTP layer.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array ROUTES_HTTP = [
	// ];

	/**
	 * CLI command dispatch entries contributed by this provider.
	 *
	 * Keep command definitions here rather than inside MAP_CLI, CFG_COMMON, or
	 * CFG_CLI. The concrete command entry contract is owned by the CitOmni CLI layer.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array COMMANDS_CLI = [
	// ];


}

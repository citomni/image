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

namespace CitOmni\Image\Service;

use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Kernel\Service\BaseService;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * CaptchaImage: Generate captcha codes and render them as distorted PNG images.
 *
 * The service owns the code and its picture: it draws a random code from a
 * configured alphabet, renders it, and compares answers. It keeps no state
 * between requests. In HTTP apps, the "captcha" service in citomni/http runs
 * the challenge lifecycle (session, one attempt per challenge, expiry) on top
 * of this service, and its CaptchaController serves the image.
 *
 * Behavior:
 * - code() draws a code with the CSPRNG. create() draws one and renders it.
 *   render() renders a given code. Images come back as PNG bytes and their
 *   geometry in a plain array.
 * - Rendering, in this fixed order:
 *   1) Lay out the glyphs crowded together with random font, size, rotation,
 *      and baseline per glyph, scaled down as a block until it fits the
 *      canvas with room for the warp
 *   2) Draw the glyphs and one interference curve through them in a single
 *      ink color, so color cannot separate the curve from the text
 *   3) Warp the text layer with a vertical and a horizontal sine wave
 *   4) Compose it over a background with low-contrast texture, lines, and
 *      speckles
 *   5) Downsample once from a canvas at twice the output size, which
 *      antialiases every edge, then encode a 64-color palette PNG
 * - verify() compares an answer with the expected code: ASCII
 *   case-insensitive, ignoring whitespace, in constant time for equal
 *   lengths. An empty expected code never verifies.
 *
 * Notes:
 * - Rendering uses GD with FreeType directly; it does not go through the
 *   Image service backends. Without FreeType, rendering fails with
 *   ImageCapabilityException instead of falling back to bitmap fonts.
 * - Distortion is randomized per image. render() accepts a "seed" option,
 *   which makes its output reproducible on the same GD and FreeType build;
 *   a challenge that stores its seed is drawn identically on every request,
 *   so reloading the image gives an attacker no second distortion to compare.
 *   A seed never affects a code.
 * - Glyph spacing is measured from rendered ink, not from GD's bounding
 *   box, whose right edge some GD builds extend to the advance width.
 * - Text captchas slow down generic form bots. They do not stop targeted
 *   attacks with modern OCR or vision models, and they exclude users who
 *   cannot solve visual challenges. Combine with rate limiting, and offer
 *   another path where accessibility matters.
 * - No SQL. No transport concerns. No session access.
 *
 * Typical usage:
 *   // Challenge lifecycle (citomni/http keeps code and seed in the session):
 *   $code = $this->app->captchaImage->code();
 *   $png  = $this->app->captchaImage->render($code, ['seed' => $seed])['data'];
 *   $ok   = $this->app->captchaImage->verify($code, $answer);
 *
 *   // Code and image at once, outside such a lifecycle:
 *   $captcha = $this->app->captchaImage->create();
 */
final class CaptchaImage extends BaseService {

	// Work canvas scale. Everything is drawn at this multiple of the output
	// size and downsampled once at the end. 3 is not visibly better at
	// captcha sizes and costs about 50% more time.
	private const SUPERSAMPLE = 2;

	// Font size (points) at which glyph ink is measured once per font and glyph.
	private const REFERENCE_SIZE = 40.0;

	// Output palette size. The image holds one ink, one background and their
	// tints, so 64 colors are indistinguishable from truecolor at a third of
	// the bytes.
	private const PALETTE_COLORS = 64;

	// Smallest legible glyph height in output pixels.
	private const MIN_GLYPH_PX = 10;

	// Bounds for developer-supplied values; the pixel budget applies on top.
	private const MAX_LENGTH = 64;
	private const MAX_SIDE = 65_535;

	// Upper bound for image.max_pixels, as in the Image service.
	private const MAX_PIXELS_CEILING = 2_147_483_647;

	// Layout, relative to the target glyph height unless noted.
	private const GLYPH_HEIGHT = 0.5;       // of the canvas height
	private const MAX_ROTATION = 16.0;      // degrees, either direction
	private const SCALE_RANGE = [0.9, 1.08];
	private const RISE = 0.1;               // baseline jitter, either direction
	private const OVERLAP_RANGE = [0.05, 0.15];
	private const PAD_X = 0.03;             // of the canvas width
	private const PAD_Y = 0.04;             // of the canvas height
	private const SAFETY_PX = 2;            // output pixels

	// Warp: amplitude relative to the canvas side it displaces along,
	// wavelength relative to the side it runs along.
	private const WARP_Y_AMPLITUDE = [0.045, 0.07];
	private const WARP_Y_WAVELENGTH = [0.55, 1.0];
	private const WARP_X_AMPLITUDE = [0.006, 0.014];
	private const WARP_X_WAVELENGTH = [0.7, 1.3];

	// Interference curve thickness relative to the final glyph height. Stems
	// of the bundled fonts are about 0.13; the curve stays clearly thinner.
	private const CURVE_WIDTH = 0.075;

	private const SETTING_KEYS = [
		'alphabet' => true,
		'length' => true,
		'width' => true,
		'height' => true,
		'background' => true,
		'colors' => true,
		'fonts' => true,
	];

	private const CODE_OPTION_KEYS = [
		'alphabet' => true,
		'length' => true,
	];

	private const CREATE_OPTION_KEYS = self::SETTING_KEYS;

	private const RENDER_OPTION_KEYS = [
		'width' => true,
		'height' => true,
		'background' => true,
		'colors' => true,
		'fonts' => true,
		'seed' => true,
	];

	/** @var array{alphabet:list<string>, length:int, width:int, height:int, background:array{0:int, 1:int, 2:int}, colors:list<array{0:int, 1:int, 2:int}>, fonts:list<string>} */
	private array $defaults;

	private int $maxPixels = 0;
	private int $pngCompression = 6;

	/** @var array<string, array{0:int, 1:int, 2:int, 3:int}> Ink box per font and glyph at REFERENCE_SIZE: x0, y0, x1, y1 from the origin. */
	private array $inkBoxes = [];

	/** @var array<string, true> Font files found readable. */
	private array $readableFonts = [];


	/**
	 * Load and validate the captcha policy from cfg.
	 *
	 * Behavior:
	 * - Reads image.captcha.*, image.max_pixels, and image.png.compression
	 *   once. Font files are not touched here; they are checked on first use.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When a cfg value is invalid.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->image;
		$captcha = $cfg->captcha;

		$this->maxPixels = self::cfgInt($cfg->max_pixels, 'image.max_pixels', 1, self::MAX_PIXELS_CEILING);
		$this->pngCompression = self::cfgInt($cfg->png->compression, 'image.png.compression', 0, 9);

		$defaults = [];

		foreach (\array_keys(self::SETTING_KEYS) as $key) {
			$defaults[$key] = self::setting($key, $captcha->{$key}, 'Config "image.captcha.' . $key . '"', \UnexpectedValueException::class);
		}

		/** @var array{alphabet:list<string>, length:int, width:int, height:int, background:array{0:int, 1:int, 2:int}, colors:list<array{0:int, 1:int, 2:int}>, fonts:list<string>} $defaults */
		$this->defaults = $defaults;

		if ($this->exceedsPixelBudget($defaults['width'], $defaults['height'])) {
			throw new \UnexpectedValueException(\sprintf('Config "image.captcha" size %dx%d needs a %dx%d work canvas, above image.max_pixels (%d).', $defaults['width'], $defaults['height'], $defaults['width'] * self::SUPERSAMPLE, $defaults['height'] * self::SUPERSAMPLE, $this->maxPixels));
		}
	}


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Draw a random code without rendering it.
	 *
	 * Behavior:
	 * - The code has "length" characters, each drawn uniformly from
	 *   "alphabet" with the CSPRNG (Random\Engine\Secure).
	 *
	 * Options (each overrides the image.captcha cfg value for this call):
	 * - alphabet (string): At least two distinct characters, without
	 *   whitespace or control characters.
	 * - length (int 1-64): Number of characters.
	 *
	 * Notes:
	 * - For a challenge whose image is requested later: store the code (and a
	 *   seed for render()) on the server and never send the code to the client.
	 *
	 * Typical usage:
	 *   $code = $this->app->captchaImage->code();
	 *
	 * @param array<string, mixed> $options Per-call overrides.
	 * @return string Code.
	 * @throws \InvalidArgumentException When an option is unknown or invalid.
	 */
	public function code(array $options = []): string {
		return \implode('', $this->drawCode($this->settings($options, self::CODE_OPTION_KEYS)));
	}


	/**
	 * Draw a random code and render it.
	 *
	 * Behavior:
	 * - The code is drawn as by code(); the image is rendered as by render()
	 *   without a seed.
	 *
	 * Options (each overrides the image.captcha cfg value for this call):
	 * - alphabet, length: As for code().
	 * - width, height (int): Output size in pixels.
	 * - background ("#rrggbb"): Background color.
	 * - colors (list<"#rrggbb">): Ink colors; one is picked per image.
	 * - fonts (list<string>): TrueType/OpenType files; one is picked per glyph.
	 *
	 * Notes:
	 * - Keep "code" on the server and never send it to the client.
	 *
	 * Typical usage:
	 *   $captcha = $this->app->captchaImage->create();
	 *
	 * @param array<string, mixed> $options Per-call overrides.
	 * @return array{code:string, data:string, format:string, mime:string, width:int, height:int, bytes:int} Code and PNG.
	 * @throws \InvalidArgumentException When an option is unknown or invalid, or the code does not fit legibly.
	 * @throws ImageCapabilityException When GD lacks FreeType support.
	 * @throws \RuntimeException When a font is unreadable or GD fails.
	 */
	public function create(array $options = []): array {
		$settings = $this->settings($options, self::CREATE_OPTION_KEYS);
		$glyphs = $this->drawCode($settings);

		return ['code' => \implode('', $glyphs)] + $this->draw($glyphs, $settings, null);
	}


	/**
	 * Render a given code.
	 *
	 * Behavior:
	 * - Draws every Unicode code point of $code as one glyph; see the class
	 *   description for the pipeline.
	 * - Fails when the glyphs cannot be drawn at least 10 output pixels tall
	 *   within the canvas.
	 *
	 * Options (each overrides the image.captcha cfg value for this call):
	 * - width, height, background, colors, fonts: As for create().
	 * - seed (int): Seeds the distortion, making the output reproducible on
	 *   the same GD and FreeType build. Omitted: seeded from the CSPRNG.
	 *
	 * Notes:
	 * - $code is developer input: any visible characters the fonts can draw,
	 *   for example "7+3" with the expected answer "10".
	 *
	 * Typical usage:
	 *   $png = $this->app->captchaImage->render('K7MPX', ['seed' => 42])['data'];
	 *
	 * @param string $code Characters to draw.
	 * @param array<string, mixed> $options Per-call overrides.
	 * @return array{data:string, format:string, mime:string, width:int, height:int, bytes:int} PNG.
	 * @throws \InvalidArgumentException When the code or an option is invalid, or the code does not fit legibly.
	 * @throws ImageCapabilityException When GD lacks FreeType support.
	 * @throws \RuntimeException When a font is unreadable or GD fails.
	 */
	public function render(string $code, array $options = []): array {
		$settings = $this->settings($options, self::RENDER_OPTION_KEYS);
		$glyphs = self::glyphs($code);

		if ($glyphs === null) {
			throw new \InvalidArgumentException('Captcha code must be non-empty UTF-8 without whitespace or control characters.');
		}

		$seed = $options['seed'] ?? null;

		if ($seed !== null && !\is_int($seed)) {
			throw new \InvalidArgumentException('Option "seed" must be an int.');
		}

		return $this->draw($glyphs, $settings, $seed);
	}


	/**
	 * Check an answer against the expected code.
	 *
	 * Behavior:
	 * - Both values are normalized the same way: Unicode whitespace removed,
	 *   ASCII letters uppercased.
	 * - An empty expected code never verifies, so a missing session value
	 *   cast to "" cannot be matched by an empty answer.
	 * - Invalid UTF-8 never verifies.
	 * - Normalized values are compared with hash_equals().
	 *
	 * Notes:
	 * - Pure comparison. Consuming the expected code (one attempt per image)
	 *   is the caller's job; without it, one solved image can be replayed.
	 *   The "captcha" service in citomni/http does that for HTTP forms.
	 *
	 * Typical usage:
	 *   $ok = $this->app->captchaImage->verify($code, $answer);
	 *
	 * @param string $expected Code from code() or create(), or the answer of a rendered challenge.
	 * @param string $answer User input.
	 * @return bool True when the answer matches.
	 */
	public function verify(string $expected, string $answer): bool {
		$expected = self::normalizeAnswer($expected);
		$answer = self::normalizeAnswer($answer);

		return $expected !== null && $expected !== '' && $answer !== null && \hash_equals($expected, $answer);
	}


	// ----------------------------------------------------------------
	// Rendering
	// ----------------------------------------------------------------

	/**
	 * Draw "length" glyphs uniformly from "alphabet" with the CSPRNG.
	 *
	 * @param array{alphabet:list<string>, length:int} $settings Resolved settings.
	 * @return list<string> Glyphs.
	 */
	private function drawCode(array $settings): array {
		$alphabet = $settings['alphabet'];
		$last = \count($alphabet) - 1;
		$secure = new Randomizer();
		$glyphs = [];

		for ($i = 0; $i < $settings['length']; $i++) {
			$glyphs[] = $alphabet[$secure->getInt(0, $last)];
		}

		return $glyphs;
	}


	/**
	 * Render glyphs into a PNG.
	 *
	 * @param list<string> $glyphs One string per glyph.
	 * @param array{alphabet:list<string>, length:int, width:int, height:int, background:array{0:int, 1:int, 2:int}, colors:list<array{0:int, 1:int, 2:int}>, fonts:list<string>} $settings Resolved settings.
	 * @param int|null $seed Distortion seed, or null for a CSPRNG seed.
	 * @return array{data:string, format:string, mime:string, width:int, height:int, bytes:int} PNG.
	 * @throws \InvalidArgumentException When the glyphs do not fit legibly.
	 * @throws ImageCapabilityException When GD lacks FreeType support.
	 * @throws \RuntimeException When a font is unreadable or GD fails.
	 */
	private function draw(array $glyphs, array $settings, ?int $seed): array {
		if (!\function_exists('imagettftext')) {
			throw new ImageCapabilityException('Captcha rendering requires GD with FreeType support.');
		}

		$width = $settings['width'];
		$height = $settings['height'];

		// Necessary, not sufficient: lets an absurdly long code fail before any glyph is measured.
		if (\count($glyphs) * self::MIN_GLYPH_PX > 4 * $width) {
			throw new \InvalidArgumentException(\sprintf('Captcha of %dx%d is too small for %d characters.', $width, $height, \count($glyphs)));
		}

		$fonts = $this->readable($settings['fonts']);
		$rng = new Randomizer(new Xoshiro256StarStar($seed));
		$canvasWidth = $width * self::SUPERSAMPLE;
		$canvasHeight = $height * self::SUPERSAMPLE;
		$ink = $settings['colors'][$rng->getInt(0, \count($settings['colors']) - 1)];
		$background = $settings['background'];

		// -- 1. Plan: warp first, since the layout leaves room for it -----
		$warp = [
			'ay' => $canvasHeight * $rng->getFloat(...self::WARP_Y_AMPLITUDE),
			'ky' => 2 * \M_PI / ($canvasWidth * $rng->getFloat(...self::WARP_Y_WAVELENGTH)),
			'py' => $rng->getFloat(0.0, 2 * \M_PI),
			'ax' => $canvasWidth * $rng->getFloat(...self::WARP_X_AMPLITUDE),
			'kx' => 2 * \M_PI / ($canvasHeight * $rng->getFloat(...self::WARP_X_WAVELENGTH)),
			'px' => $rng->getFloat(0.0, 2 * \M_PI),
		];

		$layout = $this->layout($glyphs, $fonts, $canvasWidth, $canvasHeight, $warp, $rng);

		// -- 2. Text layer: glyphs and interference curve in one ink -------
		$text = self::layer($canvasWidth, $canvasHeight);
		\imagealphablending($text, true);
		$inkColor = self::color($text, $ink, 0);

		foreach ($layout['glyphs'] as [$glyph, $font, $size, $angle, $x, $y]) {
			if (@\imagettftext($text, $size, $angle, $x, $y, $inkColor, $font, $glyph) === false) {
				throw new \RuntimeException('GD failed to draw a captcha glyph with font: ' . $font);
			}
		}

		// The curve runs diagonally through the text with a slight S-bend, so
		// it crosses each glyph at a different height. A level line through
		// the middle would turn F into E and C into G for human readers too.
		$span = $layout['right'] - $layout['left'];
		$band = $layout['bottom'] - $layout['top'];
		$middle = ($layout['top'] + $layout['bottom']) / 2;
		$slope = $rng->getInt(0, 1) === 1 ? $band : -$band;
		$startX = \max(0.0, $layout['left'] - $span * $rng->getFloat(0.02, 0.1));
		$endX = \min((float)$canvasWidth, $layout['right'] + $span * $rng->getFloat(0.02, 0.1));
		$curve = [
			[$startX, $middle + $slope * $rng->getFloat(0.2, 0.4)],
			[$startX + ($endX - $startX) * $rng->getFloat(0.25, 0.4), $middle + $slope * $rng->getFloat(-0.1, 0.45)],
			[$startX + ($endX - $startX) * $rng->getFloat(0.6, 0.75), $middle - $slope * $rng->getFloat(-0.1, 0.45)],
			[$endX, $middle - $slope * $rng->getFloat(0.2, 0.4)],
		];
		$radius = $layout['glyph_height'] * self::CURVE_WIDTH / 2;
		self::stroke($text, $curve, $radius, $inkColor);

		// The ink lies inside the glyph bounds and the curve's control-point
		// hull. Later passes touch only this region; the margin absorbs
		// hinting differences between the measured and the drawn glyphs.
		$curveY = \array_column($curve, 1);
		$margin = $radius + 2 * self::SAFETY_PX * self::SUPERSAMPLE;
		$region = [
			\max(0, (int)\floor(\min($layout['left'], $startX) - $margin)),
			\max(0, (int)\floor(\min($layout['top'], ...$curveY) - $margin)),
			\min($canvasWidth - 1, (int)\ceil(\max($layout['right'], $endX) + $margin)),
			\min($canvasHeight - 1, (int)\ceil(\max($layout['bottom'], ...$curveY) + $margin)),
		];

		// -- 3. Warp ------------------------------------------------------
		[$text, $region] = self::warp($text, $canvasWidth, $canvasHeight, $warp, $region);

		// -- 4. Background, then the text on top ---------------------------
		$canvas = \imagecreatetruecolor($canvasWidth, $canvasHeight);

		if ($canvas === false) {
			throw new \RuntimeException('GD failed to allocate the captcha canvas.');
		}

		\imagealphablending($canvas, false);
		\imagefilledrectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, self::color($canvas, $background, 0));
		\imagealphablending($canvas, true);
		self::texture($canvas, $canvasWidth, $canvasHeight, $background, $ink, $rng);
		[$x0, $y0, $x1, $y1] = $region;
		\imagecopy($canvas, $text, $x0, $y0, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1);

		// -- 5. Downsample and encode --------------------------------------
		$output = \imagecreatetruecolor($width, $height);

		if ($output === false || !\imagecopyresampled($output, $canvas, 0, 0, 0, 0, $width, $height, $canvasWidth, $canvasHeight)) {
			throw new \RuntimeException('GD failed to downsample the captcha.');
		}

		$data = $this->png($output);

		return [
			'data' => $data,
			'format' => 'png',
			'mime' => 'image/png',
			'width' => $width,
			'height' => $height,
			'bytes' => \strlen($data),
		];
	}


	/**
	 * Place the glyphs on the work canvas.
	 *
	 * Behavior:
	 * - Draws every random choice first (font, rotation, scale, rise,
	 *   overlap per glyph), so the fit below cannot change the sequence.
	 * - Sizes are relative to the tallest glyph of the code in each font, so
	 *   fonts render at the same height and glyphs keep their proportions.
	 * - Each glyph's extent is its measured ink box, scaled and rotated.
	 *   Glyphs are chained left to right, each overlapping the previous one.
	 * - Every coordinate is proportional to the glyph height, so the block is
	 *   fitted in one step: scaled down until it fits inside the padding plus
	 *   the warp amplitude, then placed at a random position in the slack.
	 *
	 * @param list<string> $glyphs Glyphs.
	 * @param list<string> $fonts Readable font files.
	 * @param int $width Canvas width.
	 * @param int $height Canvas height.
	 * @param array{ay:float, ky:float, py:float, ax:float, kx:float, px:float} $warp Warp parameters.
	 * @param Randomizer $rng Distortion randomness.
	 * @return array{glyphs:list<array{0:string, 1:string, 2:float, 3:float, 4:int, 5:int}>, left:float, top:float, right:float, bottom:float, glyph_height:float} Draw calls (glyph, font, size, angle, x, y) and the ink bounds.
	 * @throws \InvalidArgumentException When the glyphs cannot be drawn legibly.
	 * @throws \RuntimeException When FreeType fails.
	 */
	private function layout(array $glyphs, array $fonts, int $width, int $height, array $warp, Randomizer $rng): array {
		$picks = [];

		foreach ($glyphs as $glyph) {
			$picks[] = [
				'glyph' => $glyph,
				'font' => $fonts[$rng->getInt(0, \count($fonts) - 1)],
				'angle' => $rng->getFloat(-self::MAX_ROTATION, self::MAX_ROTATION),
				'scale' => $rng->getFloat(...self::SCALE_RANGE),
				'rise' => $rng->getFloat(-self::RISE, self::RISE),
				'overlap' => $rng->getFloat(...self::OVERLAP_RANGE),
			];
		}

		// Reference height per font used: the tallest ink of any glyph of the code.
		$reference = [];

		foreach ($picks as $pick) {
			if (isset($reference[$pick['font']])) {
				continue;
			}

			$tallest = 0;

			foreach ($glyphs as $glyph) {
				$box = $this->inkBox($pick['font'], $glyph);
				$tallest = \max($tallest, $box[3] - $box[1]);
			}

			$reference[$pick['font']] = $tallest;
		}

		// Chain the glyphs at the target height; everything below scales with it.
		$glyphHeight = $height * self::GLYPH_HEIGHT;
		$cursor = 0.0;
		$left = $top = \INF;
		$right = $bottom = -\INF;
		$placed = [];

		foreach ($picks as $i => $pick) {
			$size = self::REFERENCE_SIZE * $glyphHeight * $pick['scale'] / $reference[$pick['font']];
			[$minX, $minY, $maxX, $maxY] = self::rotatedBox($this->inkBox($pick['font'], $pick['glyph']), $size / self::REFERENCE_SIZE, $pick['angle']);

			if ($i > 0) {
				$cursor -= $pick['overlap'] * $glyphHeight;
			}

			$x = $cursor - $minX;
			$y = $pick['rise'] * $glyphHeight - ($minY + $maxY) / 2;
			$cursor = $x + $maxX;

			$left = \min($left, $x + $minX);
			$right = \max($right, $x + $maxX);
			$top = \min($top, $y + $minY);
			$bottom = \max($bottom, $y + $maxY);
			$placed[] = [$pick['glyph'], $pick['font'], $size, $pick['angle'], $x, $y];
		}

		// Fit inside padding, warp amplitude, and a safety margin for hinting.
		$safety = self::SAFETY_PX * self::SUPERSAMPLE;
		$padX = $width * self::PAD_X + $warp['ax'] + $safety;
		$padY = $height * self::PAD_Y + $warp['ay'] + $safety;
		$availableWidth = $width - 2 * $padX;
		$availableHeight = $height - 2 * $padY;
		$fit = $availableWidth > 0 && $availableHeight > 0
			? \min(1.0, $availableWidth / ($right - $left), $availableHeight / ($bottom - $top))
			: 0.0;
		$finalHeight = $glyphHeight * $fit / self::SUPERSAMPLE;

		if ($finalHeight < self::MIN_GLYPH_PX) {
			throw new \InvalidArgumentException(\sprintf('Captcha of %dx%d is too small for %d characters: glyphs would be %.1f px tall, at least %d px are needed.', \intdiv($width, self::SUPERSAMPLE), \intdiv($height, self::SUPERSAMPLE), \count($glyphs), $finalHeight, self::MIN_GLYPH_PX));
		}

		$offsetX = $padX + ($availableWidth - ($right - $left) * $fit) * $rng->getFloat(0.25, 0.75) - $left * $fit;
		$offsetY = $padY + ($availableHeight - ($bottom - $top) * $fit) * $rng->getFloat(0.35, 0.65) - $top * $fit;
		$draws = [];

		foreach ($placed as [$glyph, $font, $size, $angle, $x, $y]) {
			$draws[] = [$glyph, $font, $size * $fit, $angle, (int)\round($offsetX + $x * $fit), (int)\round($offsetY + $y * $fit)];
		}

		return [
			'glyphs' => $draws,
			'left' => $offsetX + $left * $fit,
			'top' => $offsetY + $top * $fit,
			'right' => $offsetX + $right * $fit,
			'bottom' => $offsetY + $bottom * $fit,
			'glyph_height' => $glyphHeight * $fit,
		];
	}


	/**
	 * Measure a glyph's ink at REFERENCE_SIZE, once per font and glyph.
	 *
	 * Behavior:
	 * - Renders the glyph inside GD's bounding box (a superset of the ink on
	 *   every build) and scans inward from each edge to the first pixel at
	 *   least half covered.
	 *
	 * @param string $font Readable font file.
	 * @param string $glyph One glyph.
	 * @return array{0:int, 1:int, 2:int, 3:int} x0, y0, x1, y1 relative to the origin on the baseline; x1 and y1 exclusive.
	 * @throws \InvalidArgumentException When the glyph has no visible ink in the font.
	 * @throws \RuntimeException When FreeType cannot use the font.
	 */
	private function inkBox(string $font, string $glyph): array {
		$key = $font . "\0" . $glyph;

		if (isset($this->inkBoxes[$key])) {
			return $this->inkBoxes[$key];
		}

		$bounds = @\imagettfbbox(self::REFERENCE_SIZE, 0, $font, $glyph);

		if ($bounds === false) {
			throw new \RuntimeException('FreeType cannot use the captcha font: ' . $font);
		}

		$pad = 2;
		$originX = $pad - \min($bounds[0], $bounds[6]);
		$originY = $pad - \min($bounds[5], $bounds[7]);
		$canvasWidth = \max($bounds[2], $bounds[4]) + $originX + $pad;
		$canvasHeight = \max($bounds[1], $bounds[3]) + $originY + $pad;
		$canvas = \imagecreatetruecolor(\max(1, $canvasWidth), \max(1, $canvasHeight));

		if ($canvas === false || @\imagettftext($canvas, self::REFERENCE_SIZE, 0, $originX, $originY, 0xFFFFFF, $font, $glyph) === false) {
			throw new \RuntimeException('GD failed to measure a captcha glyph with font: ' . $font);
		}

		// White on black: the blue channel is the coverage. Each scan stops at
		// the first inked row or column, so only the bearings are read.
		$x0 = $y0 = \PHP_INT_MAX;
		$x1 = $y1 = -1;

		for ($y = 0; $y < $canvasHeight && $y0 === \PHP_INT_MAX; $y++) {
			for ($x = 0; $x < $canvasWidth; $x++) {
				if ((\imagecolorat($canvas, $x, $y) & 0xFF) >= 128) {
					$y0 = $y;
					break;
				}
			}
		}

		if ($y0 === \PHP_INT_MAX) {
			throw new \InvalidArgumentException('Captcha glyph "' . $glyph . '" has no visible ink in font: ' . $font);
		}

		for ($y = $canvasHeight - 1; $y1 === -1; $y--) {
			for ($x = 0; $x < $canvasWidth; $x++) {
				if ((\imagecolorat($canvas, $x, $y) & 0xFF) >= 128) {
					$y1 = $y;
					break;
				}
			}
		}

		for ($x = 0; $x0 === \PHP_INT_MAX; $x++) {
			for ($y = $y0; $y <= $y1; $y++) {
				if ((\imagecolorat($canvas, $x, $y) & 0xFF) >= 128) {
					$x0 = $x;
					break;
				}
			}
		}

		for ($x = $canvasWidth - 1; $x1 === -1; $x--) {
			for ($y = $y0; $y <= $y1; $y++) {
				if ((\imagecolorat($canvas, $x, $y) & 0xFF) >= 128) {
					$x1 = $x;
					break;
				}
			}
		}

		return $this->inkBoxes[$key] = [$x0 - $originX, $y0 - $originY, $x1 + 1 - $originX, $y1 + 1 - $originY];
	}


	/**
	 * Axis-aligned extent of an ink box after scaling and GD's rotation.
	 *
	 * GD rotates counter-clockwise about the origin with y pointing down:
	 * x' = x cos a + y sin a, y' = -x sin a + y cos a.
	 *
	 * @param array{0:int, 1:int, 2:int, 3:int} $box Ink box at REFERENCE_SIZE.
	 * @param float $scale Size relative to REFERENCE_SIZE.
	 * @param float $angle Degrees, counter-clockwise.
	 * @return array{0:float, 1:float, 2:float, 3:float} minX, minY, maxX, maxY.
	 */
	private static function rotatedBox(array $box, float $scale, float $angle): array {
		$radians = \deg2rad($angle);
		$cos = \cos($radians);
		$sin = \sin($radians);
		$xs = [];
		$ys = [];

		foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$x, $y]) {
			$xs[] = ($x * $cos + $y * $sin) * $scale;
			$ys[] = (-$x * $sin + $y * $cos) * $scale;
		}

		return [\min($xs), \min($ys), \max($xs), \max($ys)];
	}


	/**
	 * Displace the text layer with a vertical, then a horizontal sine wave.
	 *
	 * Behavior:
	 * - Moves columns up or down, then rows left or right: one strip copy per
	 *   column and row inside the ink region, not per-pixel PHP work.
	 * - On the supersampled canvas the integer offsets become sub-pixel steps
	 *   after downsampling.
	 *
	 * @param \GdImage $layer Transparent layer with the text.
	 * @param int $width Canvas width.
	 * @param int $height Canvas height.
	 * @param array{ay:float, ky:float, py:float, ax:float, kx:float, px:float} $warp Warp parameters.
	 * @param array{0:int, 1:int, 2:int, 3:int} $region Inclusive x0, y0, x1, y1 holding all ink.
	 * @return array{0:\GdImage, 1:array{0:int, 1:int, 2:int, 3:int}} Warped layer and the region now holding all ink.
	 */
	private static function warp(\GdImage $layer, int $width, int $height, array $warp, array $region): array {
		[$x0, $y0, $x1, $y1] = $region;
		$columns = self::layer($width, $height);

		for ($x = $x0; $x <= $x1; $x++) {
			\imagecopy($columns, $layer, $x, $y0 + (int)\round($warp['ay'] * \sin($x * $warp['ky'] + $warp['py'])), $x, $y0, 1, $y1 - $y0 + 1);
		}

		$y0 = \max(0, $y0 - (int)\ceil($warp['ay']));
		$y1 = \min($height - 1, $y1 + (int)\ceil($warp['ay']));
		$rows = self::layer($width, $height);

		for ($y = $y0; $y <= $y1; $y++) {
			\imagecopy($rows, $columns, $x0 + (int)\round($warp['ax'] * \sin($y * $warp['kx'] + $warp['px'])), $y, $x0, $y, $x1 - $x0 + 1, 1);
		}

		return [$rows, [\max(0, $x0 - (int)\ceil($warp['ax'])), $y0, \min($width - 1, $x1 + (int)\ceil($warp['ax'])), $y1]];
	}


	/**
	 * Paint the background texture: soft blobs, thin lines, and speckles.
	 *
	 * Behavior:
	 * - Every element uses a tint between the background and the ink, much
	 *   closer to the background, so it adds noise without hiding glyphs.
	 * - Element counts scale with the output area.
	 *
	 * @param \GdImage $canvas Background canvas, alpha blending on.
	 * @param int $width Canvas width.
	 * @param int $height Canvas height.
	 * @param array{0:int, 1:int, 2:int} $background Background color.
	 * @param array{0:int, 1:int, 2:int} $ink Ink color.
	 * @param Randomizer $rng Distortion randomness.
	 * @return void
	 */
	private static function texture(\GdImage $canvas, int $width, int $height, array $background, array $ink, Randomizer $rng): void {
		$area = \intdiv($width * $height, self::SUPERSAMPLE * self::SUPERSAMPLE);

		for ($i = 4 + \intdiv($area, 4000); $i > 0; $i--) {
			$diameter = (int)\round($height * $rng->getFloat(0.3, 0.8));
			\imagefilledellipse($canvas, $rng->getInt(0, $width - 1), $rng->getInt(0, $height - 1), $diameter, $diameter, self::color($canvas, self::mix($background, $ink, $rng->getFloat(0.05, 0.12)), $rng->getInt(70, 100)));
		}

		\imagesetthickness($canvas, self::SUPERSAMPLE);

		for ($i = 2 + \intdiv($area, 8000); $i > 0; $i--) {
			$points = [
				[0.0, $height * $rng->getFloat(0.1, 0.9)],
				[$width * $rng->getFloat(0.2, 0.4), $height * $rng->getFloat(-0.2, 1.2)],
				[$width * $rng->getFloat(0.6, 0.8), $height * $rng->getFloat(-0.2, 1.2)],
				[(float)$width, $height * $rng->getFloat(0.1, 0.9)],
			];
			$color = self::color($canvas, self::mix($background, $ink, $rng->getFloat(0.25, 0.4)), 0);
			[$x, $y] = $points[0];

			// Thin lines need no round joints; 32 segments look smooth after downsampling.
			for ($step = 1; $step <= 32; $step++) {
				[$nextX, $nextY] = self::bezier($points, $step / 32);
				\imageline($canvas, (int)\round($x), (int)\round($y), (int)\round($nextX), (int)\round($nextY), $color);
				[$x, $y] = [$nextX, $nextY];
			}
		}

		\imagesetthickness($canvas, 1);

		for ($i = \intdiv($area, 40); $i > 0; $i--) {
			$diameter = $rng->getInt(2, 2 * self::SUPERSAMPLE);
			\imagefilledellipse($canvas, $rng->getInt(0, $width - 1), $rng->getInt(0, $height - 1), $diameter, $diameter, self::color($canvas, self::mix($background, $ink, $rng->getFloat(0.15, 0.45)), 0));
		}
	}


	/**
	 * Draw a cubic Bezier curve as a chain of filled circles.
	 *
	 * Behavior:
	 * - Circles are at most half a radius apart, which gives a smooth stroke
	 *   with round ends; GD's own thick lines leave gaps at joints.
	 *
	 * @param \GdImage $image Target.
	 * @param array{0:array{0:float, 1:float}, 1:array{0:float, 1:float}, 2:array{0:float, 1:float}, 3:array{0:float, 1:float}} $points Control points.
	 * @param float $radius Stroke radius in pixels.
	 * @param int $color Color.
	 * @return void
	 */
	private static function stroke(\GdImage $image, array $points, float $radius, int $color): void {
		[[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $points;

		// The control polygon is never shorter than the curve.
		$length = \hypot($x1 - $x0, $y1 - $y0) + \hypot($x2 - $x1, $y2 - $y1) + \hypot($x3 - $x2, $y3 - $y2);
		$steps = \max(1, (int)\ceil($length / \max(0.5, $radius / 2)));
		$diameter = \max(1, (int)\round($radius * 2));

		for ($i = 0; $i <= $steps; $i++) {
			[$x, $y] = self::bezier($points, $i / $steps);
			\imagefilledellipse($image, (int)\round($x), (int)\round($y), $diameter, $diameter, $color);
		}
	}


	/**
	 * Evaluate a cubic Bezier curve.
	 *
	 * @param array{0:array{0:float, 1:float}, 1:array{0:float, 1:float}, 2:array{0:float, 1:float}, 3:array{0:float, 1:float}} $points Control points.
	 * @param float $t Parameter, 0 to 1.
	 * @return array{0:float, 1:float} Point.
	 */
	private static function bezier(array $points, float $t): array {
		$u = 1 - $t;
		$a = $u * $u * $u;
		$b = 3 * $u * $u * $t;
		$c = 3 * $u * $t * $t;
		$d = $t * $t * $t;

		return [
			$a * $points[0][0] + $b * $points[1][0] + $c * $points[2][0] + $d * $points[3][0],
			$a * $points[0][1] + $b * $points[1][1] + $c * $points[2][1] + $d * $points[3][1],
		];
	}


	/**
	 * Allocate a fully transparent truecolor layer with alpha blending off.
	 *
	 * @param int $width Width.
	 * @param int $height Height.
	 * @return \GdImage Layer.
	 * @throws \RuntimeException When GD cannot allocate it.
	 */
	private static function layer(int $width, int $height): \GdImage {
		$layer = \imagecreatetruecolor($width, $height);

		if ($layer === false) {
			throw new \RuntimeException('GD failed to allocate a captcha layer.');
		}

		\imagealphablending($layer, false);
		\imagefilledrectangle($layer, 0, 0, $width - 1, $height - 1, \imagecolorallocatealpha($layer, 0, 0, 0, 127));

		return $layer;
	}


	/**
	 * Encode the output as a palette PNG with image.png.compression.
	 *
	 * @param \GdImage $image Output image; reduced to PALETTE_COLORS in place.
	 * @return string PNG bytes.
	 * @throws \RuntimeException When GD fails.
	 */
	private function png(\GdImage $image): string {
		if (!\imagetruecolortopalette($image, false, self::PALETTE_COLORS)) {
			throw new \RuntimeException('GD failed to reduce the captcha to a palette.');
		}

		\ob_start();

		try {
			$ok = @\imagepng($image, null, $this->pngCompression);
			$data = (string)\ob_get_contents();
		} finally {
			\ob_end_clean();
		}

		if (!$ok || $data === '') {
			throw new \RuntimeException('GD failed to encode the captcha as PNG.');
		}

		return $data;
	}


	// ----------------------------------------------------------------
	// Settings
	// ----------------------------------------------------------------

	/**
	 * Resolve per-call options over the cfg defaults.
	 *
	 * @param array<string, mixed> $options Options.
	 * @param array<string, true> $allowed Accepted option keys.
	 * @return array{alphabet:list<string>, length:int, width:int, height:int, background:array{0:int, 1:int, 2:int}, colors:list<array{0:int, 1:int, 2:int}>, fonts:list<string>} Settings.
	 * @throws \InvalidArgumentException When an option is unknown or invalid, or the size exceeds the pixel budget.
	 */
	private function settings(array $options, array $allowed): array {
		$unknown = \array_diff_key($options, $allowed);

		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unknown captcha option(s): ' . \implode(', ', \array_keys($unknown)) . '.');
		}

		$settings = $this->defaults;

		foreach ($options as $key => $value) {
			if (isset(self::SETTING_KEYS[$key])) {
				$settings[$key] = self::setting($key, $value, 'Option "' . $key . '"', \InvalidArgumentException::class);
			}
		}

		if ($this->exceedsPixelBudget($settings['width'], $settings['height'])) {
			throw new \InvalidArgumentException(\sprintf('Captcha of %dx%d needs a %dx%d work canvas, above image.max_pixels (%d).', $settings['width'], $settings['height'], $settings['width'] * self::SUPERSAMPLE, $settings['height'] * self::SUPERSAMPLE, $this->maxPixels));
		}

		return $settings;
	}


	/**
	 * Validate and normalize one setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed $value Raw value.
	 * @param string $label Name for error messages, e.g. 'Option "width"'.
	 * @param class-string<\InvalidArgumentException|\UnexpectedValueException> $error Exception class to throw.
	 * @return mixed Normalized value.
	 * @throws \InvalidArgumentException|\UnexpectedValueException When invalid.
	 */
	private static function setting(string $key, mixed $value, string $label, string $error): mixed {
		$fail = static fn(string $message): \Throwable => new $error($label . ' ' . $message);

		return match ($key) {
			'alphabet' => self::alphabet($value) ?? throw $fail('must be a string of at least two distinct characters without whitespace or control characters.'),
			'length' => \is_int($value) && $value >= 1 && $value <= self::MAX_LENGTH ? $value : throw $fail('must be an int between 1 and ' . self::MAX_LENGTH . '.'),
			'width', 'height' => \is_int($value) && $value >= 1 && $value <= self::MAX_SIDE ? $value : throw $fail('must be an int between 1 and ' . self::MAX_SIDE . '.'),
			'background' => self::parseColor($value) ?? throw $fail('must be a "#rrggbb" hex color.'),
			'colors' => self::colorList($value) ?? throw $fail('must be a non-empty list of "#rrggbb" hex colors.'),
			'fonts' => self::fontList($value) ?? throw $fail('must be a non-empty list of font file paths.'),
		};
	}


	/**
	 * Return the fonts after checking each file once per service instance.
	 *
	 * @param list<string> $fonts Font paths.
	 * @return list<string> The same paths.
	 * @throws \RuntimeException When a font file is missing or unreadable.
	 */
	private function readable(array $fonts): array {
		foreach ($fonts as $font) {
			if (!isset($this->readableFonts[$font])) {
				if (!\is_file($font) || !\is_readable($font)) {
					throw new \RuntimeException('Captcha font is missing or unreadable: ' . $font);
				}

				$this->readableFonts[$font] = true;
			}
		}

		return $fonts;
	}


	/**
	 * Whether a work canvas for width x height exceeds image.max_pixels.
	 *
	 * @param int $width Output width, 1-MAX_SIDE.
	 * @param int $height Output height, 1-MAX_SIDE.
	 * @return bool True when over budget.
	 */
	private function exceedsPixelBudget(int $width, int $height): bool {
		return $width * self::SUPERSAMPLE > \intdiv($this->maxPixels, $height * self::SUPERSAMPLE);
	}


	// ----------------------------------------------------------------
	// Values
	// ----------------------------------------------------------------

	/**
	 * Split a string into glyphs, one per Unicode code point.
	 *
	 * @param string $value Candidate.
	 * @return list<string>|null Glyphs, or null when empty, invalid UTF-8, or containing whitespace or control characters.
	 */
	private static function glyphs(string $value): ?array {
		if ($value === '' || \preg_match('/[\s\p{Cc}\p{Cf}]/u', $value) !== 0) {
			return null;
		}

		$glyphs = \preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY);

		return \is_array($glyphs) ? $glyphs : null;
	}


	/**
	 * Parse an alphabet.
	 *
	 * @param mixed $value Candidate.
	 * @return list<string>|null Distinct glyphs (at least two), or null when invalid.
	 */
	private static function alphabet(mixed $value): ?array {
		$glyphs = \is_string($value) ? self::glyphs($value) : null;

		return $glyphs !== null && \count($glyphs) >= 2 && \count(\array_unique($glyphs)) === \count($glyphs) ? $glyphs : null;
	}


	/**
	 * Parse a non-empty list of "#rrggbb" colors.
	 *
	 * @param mixed $value Candidate.
	 * @return list<array{0:int, 1:int, 2:int}>|null Colors, or null when invalid.
	 */
	private static function colorList(mixed $value): ?array {
		if (!\is_array($value) || $value === [] || !\array_is_list($value)) {
			return null;
		}

		$colors = [];

		foreach ($value as $item) {
			$color = self::parseColor($item);

			if ($color === null) {
				return null;
			}

			$colors[] = $color;
		}

		return $colors;
	}


	/**
	 * Parse a non-empty list of font paths. No IO.
	 *
	 * @param mixed $value Candidate.
	 * @return list<string>|null Paths, or null when invalid.
	 */
	private static function fontList(mixed $value): ?array {
		if (!\is_array($value) || $value === [] || !\array_is_list($value)) {
			return null;
		}

		foreach ($value as $item) {
			if (!\is_string($item) || $item === '') {
				return null;
			}
		}

		return $value;
	}


	/**
	 * Parse a "#rrggbb" color.
	 *
	 * @param mixed $value Candidate color.
	 * @return array{0:int, 1:int, 2:int}|null RGB components, or null when invalid.
	 */
	private static function parseColor(mixed $value): ?array {
		if (!\is_string($value) || \preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) !== 1) {
			return null;
		}

		$rgb = (int)\hexdec(\substr($value, 1));

		return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
	}


	/**
	 * Allocate a truecolor color.
	 *
	 * @param \GdImage $image Image.
	 * @param array{0:int, 1:int, 2:int} $rgb Color.
	 * @param int $alpha GD alpha, 0 (opaque) to 127 (transparent).
	 * @return int Color.
	 */
	private static function color(\GdImage $image, array $rgb, int $alpha): int {
		return \imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha);
	}


	/**
	 * Blend two colors.
	 *
	 * @param array{0:int, 1:int, 2:int} $from Color at 0.
	 * @param array{0:int, 1:int, 2:int} $to Color at 1.
	 * @param float $amount 0 to 1.
	 * @return array{0:int, 1:int, 2:int} Blended color.
	 */
	private static function mix(array $from, array $to, float $amount): array {
		return [
			(int)\round($from[0] + ($to[0] - $from[0]) * $amount),
			(int)\round($from[1] + ($to[1] - $from[1]) * $amount),
			(int)\round($from[2] + ($to[2] - $from[2]) * $amount),
		];
	}


	/**
	 * Normalize an answer or code for comparison.
	 *
	 * @param string $value Raw value.
	 * @return string|null Value without whitespace, ASCII letters uppercased; null for invalid UTF-8.
	 */
	private static function normalizeAnswer(string $value): ?string {
		$value = \preg_replace('/\s+/u', '', $value);

		return $value === null ? null : \strtoupper($value);
	}


	/**
	 * Validate an integer cfg value within inclusive bounds.
	 *
	 * @param mixed $value Raw cfg value.
	 * @param string $key Dotted cfg key for error messages.
	 * @param int $min Inclusive lower bound.
	 * @param int $max Inclusive upper bound.
	 * @return int Validated value.
	 * @throws \UnexpectedValueException When invalid.
	 */
	private static function cfgInt(mixed $value, string $key, int $min, int $max): int {
		if (!\is_int($value) || $value < $min || $value > $max) {
			throw new \UnexpectedValueException('Config "' . $key . '" must be an int between ' . $min . ' and ' . $max . '.');
		}

		return $value;
	}


}

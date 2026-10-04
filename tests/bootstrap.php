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
 * Shared bootstrap for citomni/image regression scripts.
 *
 * Run a script directly, e.g. `php tests/service_test.php`, or all of them
 * with `composer test`. Scripts exit non-zero on the first failed check.
 * On servers without CLI access, use tests/probes/run-tests.php.
 *
 * Provides:
 * - A minimal BaseService double, so the Kernel package need not be installed.
 * - A PSR-4 autoloader for src/ and tests/.
 * - check(), expectThrows(), testApp(), and tempDir() helpers.
 */

namespace CitOmni\Kernel\Service {

	if (!\class_exists(BaseService::class, false)) {

		abstract class BaseService {

			protected object $app;

			/** @var array<string,mixed> */
			protected array $options;

			/**
			 * Create a minimal service test double without installing the Kernel package.
			 *
			 * @param object $app Test application.
			 * @param array<string,mixed> $options Service options.
			 */
			public function __construct(object $app, array $options = []) {
				$this->app = $app;
				$this->options = $options;
				$this->init();
			}

			protected function init(): void {
			}
		}
	}
}

namespace {

	/** Report a fatal setup problem on STDERR (CLI) or the response (web runner). */
	function fail(string $message): never {
		\defined('STDERR') ? \fwrite(\STDERR, $message . "\n") : print($message . "\n");
		exit(1);
	}

	if (\PHP_VERSION_ID < 80500) {
		fail('PHP 8.5 or newer is required.');
	}

	if (!\extension_loaded('gd')) {
		fail('ext-gd is required.');
	}

	\spl_autoload_register(static function (string $class): void {
		$map = [
			'CitOmni\\Image\\Tests\\' => __DIR__ . '/',
			'CitOmni\\Image\\' => __DIR__ . '/../src/',
		];

		foreach ($map as $prefix => $directory) {
			if (\str_starts_with($class, $prefix)) {
				$file = $directory . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

				if (\is_file($file)) {
					require $file;
				}

				return;
			}
		}
	});

	$checks = 0;


	/** Check a condition independently of zend.assertions. */
	function check(bool $condition, string $message): void {
		global $checks;
		++$checks;

		if (!$condition) {
			throw new \RuntimeException('FAILED: ' . $message);
		}
	}


	/**
	 * Check that a callback throws a given exception class.
	 *
	 * @param class-string<\Throwable> $class Expected exception class.
	 * @return \Throwable The caught exception.
	 */
	function expectThrows(string $class, callable $callback, string $message): \Throwable {
		try {
			$callback();
		} catch (\Throwable $e) {
			check($e instanceof $class, $message . ' (expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage() . ')');
			return $e;
		}

		check(false, $message . ' (expected ' . $class . ', nothing thrown)');
		throw new \LogicException('unreachable');
	}


	/**
	 * Convert an associative config array into the object tree the service reads.
	 */
	function cfgTree(mixed $value): mixed {
		if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
			return $value;
		}

		$node = new \stdClass();

		foreach ($value as $key => $child) {
			$node->{$key} = cfgTree($child);
		}

		return $node;
	}


	/**
	 * Merge config like CitOmni: associative arrays deep-merge, lists are replaced.
	 *
	 * @param array<string,mixed> $base Base config.
	 * @param array<string,mixed> $override Override config.
	 * @return array<string,mixed> Merged config.
	 */
	function cfgMerge(array $base, array $override): array {
		foreach ($override as $key => $value) {
			$base[$key] = (\is_array($value) && !\array_is_list($value) && isset($base[$key]) && \is_array($base[$key]))
				? cfgMerge($base[$key], $value)
				: $value;
		}

		return $base;
	}


	/**
	 * Build a test app whose cfg is the shipped baseline with overrides applied.
	 *
	 * @param array<string,mixed> $imageOverrides Deep overrides of the image node.
	 */
	function testApp(array $imageOverrides = []): object {
		$image = cfgMerge(\CitOmni\Image\Boot\Registry::CFG_COMMON['image'], $imageOverrides);

		return (object)['cfg' => cfgTree(['image' => $image])];
	}


	/** Whether the Imagick backend can run in this process. */
	function imagickAvailable(): bool {
		return \extension_loaded('imagick') && \class_exists(\Imagick::class);
	}


	/**
	 * Create an empty temporary directory, removed at shutdown.
	 */
	function tempDir(): string {
		$dir = \sys_get_temp_dir() . '/citomni-image-test-' . \bin2hex(\random_bytes(6));
		\mkdir($dir, 0777, true);

		\register_shutdown_function(static function () use ($dir): void {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($iterator as $entry) {
				$entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
			}

			@\rmdir($dir);
		});

		return $dir;
	}


	/**
	 * Read one pixel as [r, g, b, alpha] (GD alpha: 0 opaque, 127 transparent).
	 *
	 * @return array{0:int, 1:int, 2:int, 3:int}
	 */
	function pixel(\GdImage $image, int $x, int $y): array {
		$c = \imagecolorat($image, $x, $y);

		return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
	}


	/**
	 * Compare RGB within a tolerance, ignoring alpha.
	 *
	 * @param array{0:int, 1:int, 2:int, 3?:int} $actual
	 * @param array{0:int, 1:int, 2:int} $expected
	 */
	function near(array $actual, array $expected, int $tolerance = 40): bool {
		return \abs($actual[0] - $expected[0]) <= $tolerance
			&& \abs($actual[1] - $expected[1]) <= $tolerance
			&& \abs($actual[2] - $expected[2]) <= $tolerance;
	}


	/** Decode bytes with GD for assertions. */
	function gd(string $data): \GdImage {
		$image = \imagecreatefromstring($data);
		check($image instanceof \GdImage, 'test helper could decode output');
		\imagesavealpha($image, true);

		return $image;
	}


	/** Print the summary line for a script. */
	function done(string $name): void {
		global $checks;
		echo $name . ': ' . $checks . " checks passed.\n";
	}
}

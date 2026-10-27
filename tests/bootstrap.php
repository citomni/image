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
 * Shared bootstrap for the citomni/image suites under tests/<suite>/run.php.
 *
 * Run one suite directly, e.g. `php tests/service/run.php`, or all of them
 * with `php tests/run.php` (also `composer test`). Every suite is CLI only,
 * runs in its own process, and ends with a "N passed, M failed[, K skipped]"
 * line; the exit code is 1 when anything failed.
 *
 * Provides:
 * - A minimal BaseService double, so the Kernel package need not be installed.
 * - A PSR-4 autoloader for src/ and tests/.
 * - Reporting: check(), expectThrows(), skip(), and done(), on top of pass()
 *   and fail().
 * - testApp(), tempDir(), and the image helpers.
 *
 * Notes:
 * - The error handler throws ErrorException for every error except those the
 *   @ operator silences. src/ silences decoder warnings on purpose and reads
 *   the false return, so those must reach the code unchanged.
 * - A failed check is reported and the suite goes on. An uncaught exception
 *   ends the suite with one FAIL line and the summary line.
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

	if (\PHP_SAPI !== 'cli') {
		throw new \RuntimeException('CLI only.');
	}

	\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
		// The @ operator removes an error from error_reporting() but not from this handler.
		if ((\error_reporting() & $errno) === 0) {
			return false;
		}

		throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
	});

	$passed = 0;
	$failed = 0;
	$skipped = 0;


	/**
	 * Record a passed case: a PASS line on STDOUT.
	 *
	 * @param string $name Case name.
	 * @return void
	 */
	function pass(string $name): void {
		global $passed;
		++$passed;
		\fwrite(\STDOUT, "PASS {$name}\n");
	}


	/**
	 * Record a failed case: a FAIL line on STDERR.
	 *
	 * @param string $name Case name.
	 * @param string $message What went wrong.
	 * @return void
	 */
	function fail(string $name, string $message): void {
		global $failed;
		++$failed;
		\fwrite(\STDERR, "FAIL {$name} - {$message}\n");
	}


	/**
	 * Record an optional case that cannot run here: a SKIP line on STDOUT.
	 *
	 * @param string $reason Why the case is skipped.
	 * @return void
	 */
	function skip(string $reason): void {
		global $skipped;
		++$skipped;
		\fwrite(\STDOUT, "SKIP {$reason}\n");
	}


	/**
	 * Print the summary line and end the suite; exit code 1 when a case failed.
	 *
	 * @return never
	 */
	function done(): never {
		global $passed, $failed, $skipped;
		\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
		exit($failed === 0 ? 0 : 1);
	}


	// An exception nothing caught ends the suite, but the totals still reach the runner.
	\set_exception_handler(static function (\Throwable $e): never {
		fail('suite aborted by an uncaught exception', $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
		done();
	});

	if (\PHP_VERSION_ID < 80500) {
		fail('setup', 'PHP 8.5 or newer is required.');
		done();
	}

	if (!\extension_loaded('gd')) {
		fail('setup', 'ext-gd is required.');
		done();
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


	/**
	 * Check a condition independently of zend.assertions; one PASS or FAIL line.
	 *
	 * @param bool $condition Condition that must hold.
	 * @param string $name Case name.
	 * @return void
	 */
	function check(bool $condition, string $name): void {
		$condition ? pass($name) : fail($name, 'check failed');
	}


	/**
	 * Check that a callback throws a given exception class; one PASS or FAIL line.
	 *
	 * When nothing is thrown the case fails and a LogicException stands in for the
	 * exception, so a caller that inspects it fails loudly instead of silently.
	 *
	 * @param class-string<\Throwable> $class Expected exception class.
	 * @param callable $callback Code expected to throw.
	 * @param string $name Case name.
	 * @return \Throwable The caught exception.
	 */
	function expectThrows(string $class, callable $callback, string $name): \Throwable {
		try {
			$callback();
		} catch (\Throwable $e) {
			$e instanceof $class ? pass($name) : fail($name, 'expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
			return $e;
		}

		fail($name, 'expected ' . $class . ', nothing thrown');
		return new \LogicException('Nothing thrown: ' . $name);
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
	 * Merge config like CitOmni: associative arrays deep-merge, an empty array leaves an
	 * associative array unchanged, and lists are replaced.
	 *
	 * @param array<string,mixed> $base Base config.
	 * @param array<string,mixed> $override Override config.
	 * @return array<string,mixed> Merged config.
	 */
	function cfgMerge(array $base, array $override): array {
		foreach ($override as $key => $value) {
			if ($value === [] && \is_array($base[$key] ?? null) && !\array_is_list($base[$key])) {
				continue;
			}

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
			// Best-effort cleanup: an entry that cannot be removed must not raise ErrorException here.
			\set_error_handler(static fn(): bool => true);

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

		if (!$image instanceof \GdImage) {
			throw new \RuntimeException('Test helper could not decode output.');
		}

		\imagesavealpha($image, true);

		return $image;
	}
}

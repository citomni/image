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
 * Temporary browser runner for the citomni/image regression scripts.
 *
 * Purpose:
 * - Run the real regression suite on a server without shell access, so the
 *   actual GD/ImageMagick build there is tested through the package code.
 *
 * Usage:
 * 1) Upload the package's src/ and tests/ directories (tests are not part of
 *    the Composer dist archive) to a non-public or temporary web directory.
 * 2) Set RUNNER_ENABLED to true below.
 * 3) Open run-tests.php?test=imagick (or geometry, header, service, write, color).
 * 4) Set RUNNER_ENABLED back to false and delete the uploaded files.
 *
 * Notes:
 * - One test script per request; each script defines its own helpers.
 * - Scripts write only to sys_get_temp_dir() and clean up at shutdown.
 * - Output exposes runtime details. Do not leave this reachable.
 */

const RUNNER_ENABLED = false;
const RUNNER_TESTS = ['geometry', 'header', 'service', 'write', 'imagick', 'color'];

if (!\headers_sent()) {
	\header('Content-Type: text/plain; charset=UTF-8');
	\header('X-Content-Type-Options: nosniff');
	\header('Cache-Control: no-store');
}

if (!RUNNER_ENABLED) {
	\http_response_code(403);
	echo "Runner disabled. Set RUNNER_ENABLED to true while testing, then disable and delete it.\n";
	exit;
}

$test = \is_string($_GET['test'] ?? null) ? $_GET['test'] : '';

if (!\in_array($test, RUNNER_TESTS, true)) {
	echo "citomni/image test runner\n\nChoose a test:\n";

	foreach (RUNNER_TESTS as $name) {
		echo "  ?test={$name}\n";
	}

	exit;
}

\set_time_limit(300);
\ob_implicit_flush(true);

echo "Running tests/{$test}_test.php on PHP " . \PHP_VERSION . ' (' . \PHP_SAPI . ")\n\n";

try {
	require \dirname(__DIR__) . '/' . $test . '_test.php';
} catch (\Throwable $e) {
	echo "\n" . $e::class . ': ' . $e->getMessage() . "\n";
	echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo 'Checks passed before the failure: ' . (int)($GLOBALS['checks'] ?? 0) . "\n";
}

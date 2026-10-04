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
 * Filesystem contract checks for Image::save(): successful writes, overwrite
 * and no-clobber semantics (including a target created concurrently during
 * processing), validation before IO, phase-1 failures (nothing touched),
 * phase-2 failures (exact committed/uncommitted report), encoder
 * verification, and absence of leftover temporary files.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageTargetExistsException;
use CitOmni\Image\Exception\ImageWriteException;
use CitOmni\Image\Service\Image;
use CitOmni\Image\Tests\Support\CountingBackend;
use CitOmni\Image\Tests\Support\ImageFixtures as F;

$dir = tempDir();
$source = $dir . '/source.jpg';
\file_put_contents($source, F::jpeg(6));

$gdOnly = ['backends' => ['gd']];
$image = new Image(testApp($gdOnly));
$counting = new Image(testApp($gdOnly), ['backends' => ['gd' => CountingBackend::class]]);
$counting->capabilities(); // Run codec probes now, so later counts and faults concern jobs only.

$leftovers = static fn(): array => \glob($dir . '/.*.tmp') ?: [];
$outputs = static fn(string $prefix): array => [
	'a' => ['path' => "$dir/$prefix-a.webp", 'format' => 'webp', 'width' => 100],
	'b' => ['path' => "$dir/$prefix-b.jpg", 'format' => 'jpeg', 'width' => 50, 'height' => 50, 'fit' => 'cover'],
	'c' => ['path' => "$dir/$prefix-c.png", 'format' => 'png'],
];

// -- Success ------------------------------------------------------------------------

$results = $image->save($source, $outputs('ok'));
check(\array_keys($results) === ['a', 'b', 'c'], 'results keyed in caller order');

foreach ($results as $key => $result) {
	$info = \getimagesize($result['path']);
	check(\is_array($info) && [$info[0], $info[1]] === [$result['width'], $result['height']], "file $key matches reported geometry");
	check($result['bytes'] === \filesize($result['path']) && $result['backend'] === 'gd', "file $key size and backend");
}

check([$results['c']['width'], $results['c']['height']] === [199, 301], 'oriented full-size output');
check($leftovers() === [], 'no temporary files after success');
check($image->saveString(F::jpeg(6), $outputs('str')) === \array_map(
	static fn(array $r): array => ['path' => \str_replace('/ok-', '/str-', $r['path'])] + $r,
	$results
), 'saveString matches save');

// -- Overwrite and no-clobber ----------------------------------------------------------

\file_put_contents("$dir/over.png", 'old content');
CountingBackend::reset();
$error = expectThrows(ImageTargetExistsException::class, static fn() => $counting->save($source, [
	'fresh' => ['path' => "$dir/fresh.png", 'format' => 'png'],
	'o' => ['path' => "$dir/over.png", 'format' => 'png', 'width' => 10],
]), 'existing target refused by default');
check($error instanceof ImageTargetExistsException && $error->target() === 'o' && $error->committed() === [], 'early conflict names the output, nothing committed');
check($error->uncommitted() === ['fresh' => "$dir/fresh.png", 'o' => "$dir/over.png"], 'early conflict lists all outputs as uncommitted');
check(\file_get_contents("$dir/over.png") === 'old content' && !\is_file("$dir/fresh.png"), 'early conflict touches nothing');
check(CountingBackend::$calls === [], 'early conflict happens before any decode or probe');

$image->save($source, ['o' => ['path' => "$dir/over.png", 'format' => 'png', 'width' => 10, 'overwrite' => true]]);
check(\getimagesize("$dir/over.png")[0] === 10, 'overwrite => true replaces the target');
expectThrows(\InvalidArgumentException::class, static fn() => $image->save($source, ['o' => ['path' => "$dir/x.png", 'format' => 'png', 'overwrite' => 'yes']]), 'overwrite must be bool');
expectThrows(\InvalidArgumentException::class, static fn() => $image->encode($source, ['o' => ['format' => 'png', 'overwrite' => true]]), 'overwrite not accepted by encode()');

// Race: a no-clobber target appears while outputs are being produced.
\file_put_contents("$dir/race-keep.png", 'old keep');
CountingBackend::reset();
CountingBackend::$beforeWrite = static function (string $path, int $ordinal) use ($dir): void {
	if ($ordinal === 3) {
		\file_put_contents("$dir/race-b.png", 'written by someone else');
	}
};

$error = expectThrows(ImageTargetExistsException::class, static fn() => $counting->save($source, [
	'keep' => ['path' => "$dir/race-keep.png", 'format' => 'png', 'width' => 20, 'overwrite' => true],
	'a' => ['path' => "$dir/race-a.png", 'format' => 'png', 'width' => 30],
	'b' => ['path' => "$dir/race-b.png", 'format' => 'png', 'width' => 40],
]), 'concurrently created no-clobber target is detected at publish time');
check($error instanceof ImageTargetExistsException && $error->target() === 'b', 'conflict names the racing output');
check($error->committed() === ['a' => "$dir/race-a.png"], 'only earlier no-clobber outputs were published');
check(\file_get_contents("$dir/race-b.png") === 'written by someone else', 'the concurrent file is never replaced');
check(\file_get_contents("$dir/race-keep.png") === 'old keep', 'replacing outputs publish after no-clobber ones, so nothing was replaced');
check($error->uncommitted() === ['keep' => "$dir/race-keep.png", 'b' => "$dir/race-b.png"], 'uncommitted lists the rest in output order');
check($leftovers() === [], 'conflict at publish time removes temporary files');

// -- Validation happens before any IO -----------------------------------------------

$targets = $outputs('val');
$targets['b']['path'] = "$dir/missing-dir/b.jpg";
expectThrows(\RuntimeException::class, static fn() => $image->save($source, $targets), 'missing target directory');
check(!\is_file("$dir/val-a.webp"), 'nothing written when a later target is invalid');

$targets = $outputs('dup');
$targets['c']['path'] = $targets['a']['path'];
expectThrows(\InvalidArgumentException::class, static fn() => $image->save($source, $targets), 'duplicate target paths');
$targets = $outputs('dup');
$targets['c']['path'] = $dir . '/./sub/../' . \basename($targets['a']['path']);
\mkdir("$dir/sub");
expectThrows(\InvalidArgumentException::class, static fn() => $image->save($source, $targets), 'duplicate targets detected through ./ and ../');

\mkdir("$dir/isdir.png");
expectThrows(\RuntimeException::class, static fn() => $image->save($source, ['o' => ['path' => "$dir/isdir.png", 'format' => 'png']]), 'target is a directory');

// -- Phase 1 failure: no target touched ---------------------------------------------

\file_put_contents("$dir/p1-a.webp", 'previous a');
CountingBackend::reset();
CountingBackend::$beforeWrite = static function (string $path, int $ordinal): void {
	if ($ordinal === 3) {
		throw new \RuntimeException('injected encoder failure');
	}
};

expectThrows(\RuntimeException::class, static fn() => $counting->save($source, $outputs('p1')), 'phase-1 failure propagates');
check(\file_get_contents("$dir/p1-a.webp") === 'previous a', 'phase-1 failure leaves existing targets untouched');
check(!\is_file("$dir/p1-b.jpg") && !\is_file("$dir/p1-c.png"), 'phase-1 failure creates no targets');
check($leftovers() === [], 'phase-1 failure removes temporary files');
check(CountingBackend::$live === [], 'phase-1 failure releases every handle');

// -- A failing release() cannot leave temporary files behind ---------------------------

// release() must not throw; even a backend that violates this cannot strand temporaries.
// An upright source with an identity output creates no intermediate handles, so the
// first release (and failure) happens only after phase 1 has completed.
CountingBackend::reset();
CountingBackend::$releaseThrows = true;
expectThrows(\RuntimeException::class, static fn() => $counting->saveString(F::jpeg(), ['o' => ['path' => "$dir/release.png", 'format' => 'png']]), 'release failure after a complete phase 1');
check(!isset(CountingBackend::$calls['render']) && (CountingBackend::$calls['write'] ?? 0) === 1, 'phase 1 completed before the release failure');
check(!\is_file("$dir/release.png") && $leftovers() === [], 'release failure: nothing published, no temporary files');
CountingBackend::reset();

// -- Encoder verification failure ---------------------------------------------------

CountingBackend::reset();
CountingBackend::$corruptOutput = true;
expectThrows(ImageCapabilityException::class, static fn() => $counting->save($source, $outputs('bad')), 'wrong encoder output is a capability failure');
check(!\is_file("$dir/bad-a.webp") && $leftovers() === [], 'unverified output never committed');

// -- Phase 2 failure: exact committed/uncommitted report ----------------------------

\file_put_contents("$dir/p2-c.png", 'previous c');
$p2 = $outputs('p2');

foreach ($p2 as &$output) {
	$output['overwrite'] = true;
}

unset($output);
CountingBackend::reset();
CountingBackend::$beforeWrite = static function (string $path, int $ordinal) use ($dir): void {
	if ($ordinal === 3) {
		// Make output "b" impossible to commit: its target becomes a non-empty directory.
		\mkdir("$dir/p2-b.jpg");
		\touch("$dir/p2-b.jpg/blocker");
	}
};

$error = expectThrows(ImageWriteException::class, static fn() => $counting->save($source, $p2), 'commit failure');
check(!$error instanceof ImageTargetExistsException, 'a failing replace is not a conflict');
check($error instanceof ImageWriteException, 'commit failure type');
check($error->committed() === ['a' => "$dir/p2-a.webp"], 'committed() lists exactly the replaced targets');
check($error->uncommitted() === ['b' => "$dir/p2-b.jpg", 'c' => "$dir/p2-c.png"], 'uncommitted() lists the untouched targets');
check(\getimagesize("$dir/p2-a.webp")[0] === 100, 'committed target holds the new image');
check(\file_get_contents("$dir/p2-c.png") === 'previous c', 'uncommitted target left untouched');
check($leftovers() === [], 'commit failure removes remaining temporary files');

done('write_test');

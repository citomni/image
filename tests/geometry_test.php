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
 * Regression checks for Util\Geometry: fit planning, orientation composition,
 * and display-to-stored plan mapping. Orientation semantics are checked
 * against an independent pixel-grid simulation, not against Geometry itself.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFit;
use CitOmni\Image\Util\Geometry;

// -- Grid simulation (independent reference) --------------------------------

/** @param list<list<int>> $grid */
function rotCw(array $grid): array {
	$height = \count($grid);
	$width = \count($grid[0]);
	$out = [];

	for ($y = 0; $y < $width; $y++) {
		for ($x = 0; $x < $height; $x++) {
			$out[$y][$x] = $grid[$height - 1 - $x][$y];
		}
	}

	return $out;
}

/** @param list<list<int>> $grid */
function flipH(array $grid): array {
	return \array_map('array_reverse', $grid);
}

/** @param list<list<int>> $grid */
function flipV(array $grid): array {
	return \array_reverse($grid);
}

/** EXIF orientation as the transform that makes the stored grid display upright. */
function orientGrid(array $grid, int $orientation): array {
	return match ($orientation) {
		1 => $grid,
		2 => flipH($grid),
		3 => rotCw(rotCw($grid)),
		4 => flipV($grid),
		5 => flipV(rotCw(rotCw(rotCw($grid)))),
		6 => rotCw($grid),
		7 => flipV(rotCw($grid)),
		8 => rotCw(rotCw(rotCw($grid))),
	};
}

function subGrid(array $grid, int $x, int $y, int $width, int $height): array {
	$out = [];

	for ($row = 0; $row < $height; $row++) {
		$out[] = \array_slice($grid[$y + $row], $x, $width);
	}

	return $out;
}

$stored = [];
[$storedWidth, $storedHeight] = [7, 5];

for ($y = 0; $y < $storedHeight; $y++) {
	for ($x = 0; $x < $storedWidth; $x++) {
		$stored[$y][$x] = $y * 100 + $x;
	}
}

// -- compose(): orientation followed by clockwise quarter turns -------------

for ($orientation = 1; $orientation <= 8; $orientation++) {
	foreach ([0, 90, 180, 270, 360, -90, -180, -270, 450] as $degrees) {
		$expected = orientGrid($stored, $orientation);

		for ($i = 0; $i < (((($degrees % 360) + 360) % 360) / 90); $i++) {
			$expected = rotCw($expected);
		}

		check(orientGrid($stored, Geometry::compose($orientation, $degrees)) === $expected, "compose($orientation, $degrees)");
	}
}

// -- toStored(): cropping the stored image then orienting equals cropping the display

for ($orientation = 1; $orientation <= 8; $orientation++) {
	$display = orientGrid($stored, $orientation);
	[$displayWidth, $displayHeight] = Geometry::displaySize($storedWidth, $storedHeight, $orientation);
	check(\count($display[0]) === $displayWidth && \count($display) === $displayHeight, "displaySize $orientation");

	foreach ([[0, 0, $displayWidth, $displayHeight], [1, 2, 2, 3], [0, 1, 3, 1], [$displayWidth - 2, $displayHeight - 1, 2, 1]] as [$x, $y, $w, $h]) {
		$plan = Geometry::toStored([$x, $y, $w, $h, $w * 3, $h * 2], $orientation, $storedWidth, $storedHeight);
		$fromStored = orientGrid(subGrid($stored, $plan[0], $plan[1], $plan[2], $plan[3]), $orientation);
		check($fromStored === subGrid($display, $x, $y, $w, $h), "toStored rect o=$orientation [$x,$y,$w,$h]");

		// Target size must come out right after orientation.
		$swapped = $orientation >= 5;
		check([$plan[4], $plan[5]] === ($swapped ? [$h * 2, $w * 3] : [$w * 3, $h * 2]), "toStored target o=$orientation");
	}
}

// -- fit() ------------------------------------------------------------------

$fit = static fn(int $w, int $h, ?int $tw, ?int $th, ImageFit $mode, bool $up = false): array => Geometry::fit($w, $h, $tw, $th, $mode, $up);

check($fit(4000, 3000, 800, null, ImageFit::Contain) === [0, 0, 4000, 3000, 800, 600], 'contain width');
check($fit(4000, 3000, null, 600, ImageFit::Contain) === [0, 0, 4000, 3000, 800, 600], 'contain height');
check($fit(4000, 3000, 800, 800, ImageFit::Contain) === [0, 0, 4000, 3000, 800, 600], 'contain box');
check($fit(4000, 3000, 1000, 300, ImageFit::Contain) === [0, 0, 4000, 3000, 400, 300], 'contain height-limited');
check($fit(4000, 3000, 5000, 5000, ImageFit::Contain) === [0, 0, 4000, 3000, 4000, 3000], 'contain no upscale');
check($fit(4000, 3000, 5000, null, ImageFit::Contain, true) === [0, 0, 4000, 3000, 5000, 3750], 'contain upscale');
check($fit(4000, 3000, null, null, ImageFit::Contain) === [0, 0, 4000, 3000, 4000, 3000], 'contain unconstrained');
check($fit(301, 199, 100, null, ImageFit::Contain) === [0, 0, 301, 199, 100, 66], 'contain odd rounding');
check($fit(10000, 1, 50, null, ImageFit::Contain) === [0, 0, 10000, 1, 50, 1], 'contain never below 1px');
check($fit(4000, 3000, 300, 300, ImageFit::Cover) === [500, 0, 3000, 3000, 300, 300], 'cover square');
check($fit(3000, 4000, 300, 300, ImageFit::Cover) === [0, 500, 3000, 3000, 300, 300], 'cover square portrait');
check($fit(4000, 3000, 5000, 1000, ImageFit::Cover) === [0, 1100, 4000, 800, 4000, 800], 'cover no upscale keeps ratio');
check($fit(301, 199, 97, 61, ImageFit::Cover) === [0, 5, 301, 189, 97, 61], 'cover odd');
check($fit(4000, 3000, 1, 1, ImageFit::Cover) === [500, 0, 3000, 3000, 1, 1], 'cover 1x1');
check($fit(4000, 3000, 100, 900, ImageFit::Fill) === [0, 0, 4000, 3000, 100, 900], 'fill distorts');
check($fit(400, 300, 1000, 100, ImageFit::Fill) === [0, 0, 400, 300, 400, 100], 'fill clamps per axis');
check($fit(400, 300, 1000, 100, ImageFit::Fill, true) === [0, 0, 400, 300, 1000, 100], 'fill upscale');

done('geometry_test');

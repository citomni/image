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

namespace CitOmni\Image\Enum;

/**
 * Where an overlay is anchored on an output image.
 *
 * Behavior:
 * - Edge anchors align the overlay's matching edge with the output's edge.
 * - Center axes center the overlay, rounding toward zero.
 * - An overlay's "offset" is added afterwards (+x right, +y down).
 */
enum ImageAnchor: string {
	case TopLeft = 'top-left';
	case Top = 'top';
	case TopRight = 'top-right';
	case Left = 'left';
	case Center = 'center';
	case Right = 'right';
	case BottomLeft = 'bottom-left';
	case Bottom = 'bottom';
	case BottomRight = 'bottom-right';


	/**
	 * Resolve the overlay's top-left corner on a base image.
	 *
	 * @param int $baseWidth Base width.
	 * @param int $baseHeight Base height.
	 * @param int $width Overlay width.
	 * @param int $height Overlay height.
	 * @return array{0:int, 1:int} x and y before offset.
	 */
	public function position(int $baseWidth, int $baseHeight, int $width, int $height): array {
		$x = match ($this) {
			self::TopLeft, self::Left, self::BottomLeft => 0,
			self::Top, self::Center, self::Bottom => \intdiv($baseWidth - $width, 2),
			self::TopRight, self::Right, self::BottomRight => $baseWidth - $width,
		};

		$y = match ($this) {
			self::TopLeft, self::Top, self::TopRight => 0,
			self::Left, self::Center, self::Right => \intdiv($baseHeight - $height, 2),
			self::BottomLeft, self::Bottom, self::BottomRight => $baseHeight - $height,
		};

		return [$x, $y];
	}


}

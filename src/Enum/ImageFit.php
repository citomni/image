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
 * How an image is fitted into a target box.
 *
 * Behavior:
 * - Contain: Scale to fit inside the box, preserving aspect ratio. Either box
 *   dimension may be omitted. No pixels are cut away.
 * - Cover: Scale to fill the box exactly, preserving aspect ratio, and trim
 *   the overflow equally from both sides (center crop). Both dimensions are
 *   required.
 * - Fill: Scale each axis independently to the box. Distorts the aspect ratio
 *   unless it already matches. Both dimensions are required.
 *
 * Notes:
 * - Without upscaling, no axis is ever enlarged beyond the source region.
 */
enum ImageFit: string {
	case Contain = 'contain';
	case Cover = 'cover';
	case Fill = 'fill';
}

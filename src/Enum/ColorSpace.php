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
 * Color space a source image's pixel values are encoded in.
 *
 * Behavior:
 * - Srgb: no color description (assumed sRGB, the web convention), an
 *   sRGB-equivalent ICC profile, or HEIF color information with BT.709/sRGB
 *   primaries and the sRGB transfer characteristics (unspecified values
 *   follow the untagged convention). BT.709's own transfer is not sRGB.
 * - DisplayP3, AdobeRgb: recognized RGB profiles or HEIF color information.
 * - Rgb: another RGB ICC profile (wide gamut, linear, camera, ...).
 * - Gray: a grayscale ICC profile whose tone curve is not sRGB's.
 * - Cmyk: a CMYK ICC profile, or untagged CMYK/YCCK data.
 * - Other: any other or unreadable color description.
 *
 * Notes:
 * - Everything except Srgb needs color management to be rendered as sRGB.
 */
enum ColorSpace: string {
	case Srgb = 'srgb';
	case DisplayP3 = 'display-p3';
	case AdobeRgb = 'adobe-rgb';
	case Rgb = 'rgb';
	case Gray = 'gray';
	case Cmyk = 'cmyk';
	case Other = 'other';
}

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

namespace CitOmni\Image\Color;

/**
 * Profiles: Standard ICC profiles shipped with the package.
 *
 * Behavior:
 * - srgb(): conversion target for color-managed output.
 * - displayP3(): source profile for HEIF images that describe Display P3
 *   with nclx color information instead of an embedded profile.
 *
 * Notes:
 * - Pure data; no IO.
 * - Both are ICC v4 matrix/parametric-curve profiles (480 bytes) from the
 *   Compact ICC Profiles project by Clinton Ingram, released to the public
 *   domain under CC0 1.0: https://github.com/saucecontrol/Compact-ICC-Profiles
 */
final class Profiles {

	private const SRGB =
		'AAAB4GxjbXMEIAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5keem/Vlo+AbaDI4VVRvdPqgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAAAPwAAAAk' .
		'Y3BydAAAASAAAAAid3RwdAAAAUQAAAAUY2hhZAAAAVgAAAAsclhZWgAAAYQAAAAUZ1hZWgAAAZgAAAAUYlhZWgAAAawAAAAU' .
		'clRSQwAAAcAAAAAgZ1RSQwAAAcAAAAAgYlRSQwAAAcAAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAIAAAAHABzAFIARwBC' .
		'bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDD8AAAXd' .
		'///zJgAAB5AAAP2S///7of///aIAAAPcAADAcVhZWiAAAAAAAABvoAAAOPIAAAOPWFlaIAAAAAAAAGKWAAC3iQAAGNpYWVog' .
		'AAAAAAAAJKAAAA+FAAC2xHBhcmEAAAAAAAMAAAACZmkAAPKnAAANWQAAE9AAAApb';

	private const DISPLAY_P3 =
		'AAAB4GxjbXMEIAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5kwzc6zlf4VsuhS9h6V6sQYQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAAAPwAAAAi' .
		'Y3BydAAAASAAAAAid3RwdAAAAUQAAAAUY2hhZAAAAVgAAAAsclhZWgAAAYQAAAAUZ1hZWgAAAZgAAAAUYlhZWgAAAawAAAAU' .
		'clRSQwAAAcAAAAAgZ1RSQwAAAcAAAAAgYlRSQwAAAcAAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABzAFAAMwAA' .
		'bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDEIAAAXe' .
		'///zJQAAB5MAAP2Q///7of///aIAAAPcAADAblhZWiAAAAAAAACD3wAAPb////+7WFlaIAAAAAAAAEq/AACxNwAACrlYWVog' .
		'AAAAAAAAKDgAABEKAADIuXBhcmEAAAAAAAMAAAACZmkAAPKnAAANWQAAE9AAAApb';


	/**
	 * Return the sRGB profile.
	 *
	 * @return string ICC profile bytes (sRGB-v4.icc).
	 */
	public static function srgb(): string {
		return (string)\base64_decode(self::SRGB, true);
	}


	/**
	 * Return the Display P3 profile.
	 *
	 * @return string ICC profile bytes (DisplayP3-v4.icc).
	 */
	public static function displayP3(): string {
		return (string)\base64_decode(self::DISPLAY_P3, true);
	}


}

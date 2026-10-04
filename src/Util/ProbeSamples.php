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

namespace CitOmni\Image\Util;

use CitOmni\Image\Enum\ImageFormat;

/**
 * ProbeSamples: Known-good encoded images for verifying decoders.
 *
 * Decode support is verified against these canonical files, independently of
 * whether (or how well) a backend can encode the format. Builds that decode a
 * format they cannot encode, such as HEIC without x265, are therefore not
 * reported as unable to decode it.
 *
 * Behavior:
 * - get(): one still image per format, 65 x 49 pixels, opaque.
 *   1) HEIC: four quadrants (red, green / blue, yellow) with a clean-aperture
 *      crop (coded 66 x 64), so tests can verify container transforms on
 *      decode-only builds
 *   2) BMP: 1-bit, black and white
 *   3) Others: rgb(200, 40, 60) left, rgb(40, 170, 210) right
 * - multiFrame(): two frames of 65 x 49, frame 0 red, frame 1 blue, for the
 *   formats whose first frame the package can render (GIF, APNG, WebP, TIFF).
 * - colorManaged(): a Display P3 PNG for verifying ICC conversion.
 *
 * Notes:
 * - Pure data; no IO.
 * - Produced by Pillow, GD (GIF still), and libheif via ImageMagick (HEIC:
 *   x265, AVIF: aom); every sample is decoded by GD and ImageMagick in the
 *   regression suite.
 * - JPEG is baseline, WebP lossy (VP8), TIFF LZW-compressed.
 */
final class ProbeSamples {

	private const STILL = [
		'jpeg' =>
			'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdB' .
			'RkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P' .
			'T09PT09PT09PT09PT0//wAARCAAxAEEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAAAAMF/8QAGRABAAIDAAAAAAAA' .
			'AAAAAAAAAAEDNHKx/8QAGQEBAAMBAQAAAAAAAAAAAAAAAAIGBwME/8QAGxEBAAICAwAAAAAAAAAAAAAAAAECAzIzcbH/2gAM' .
			'AwEAAhEDEQA/AMgB5VtAAbFGPXrHFE6MevWOKLBTWGYZ+W3c+gCTkAAwwFdaoAA2KMevWOKJ0Y9escUWCmsMwz8tu59AEnIA' .
			'BhgK61QABsUY9escUTox69Y4osFNYZhn5bdz6AJOQADDAV1qgADYox69Y4oCwU1hmGflt3PoAk5AAP/Z',
		'png' =>
			'iVBORw0KGgoAAAANSUhEUgAAAEEAAAAxCAIAAAAKt1PTAAAAUElEQVR42u3PQQ0AIAwAsYEaRCAGLYiYJmShYo8lPQGXdLy1' .
			'o7hzs/Q/o38MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDD371CUDBvcOvXIAAAAASUVORK5CYII=',
		'gif' =>
			'R0lGODdhQQAxAIAAAMwqPCyq1CwAAAAAQQAxAAACh4SPqZvhD6OckNmrqN4R+8uF2kdm4lmVKoC2waq6KFzKJ03aIv7pIe/x' .
			'cYAY4YYIMlKQFuWSuXBOoFFph9qwprAH7ZbL8jrAXfGLHBaj0961+Ux+u81zNVp+p+ftcX2fD4b317ZHOKhVZxjotwjIJdio' .
			'+Mg46YgFWSl5SblpSYXZqfnJOSpWAAA7',
		'webp' =>
			'UklGRn4AAABXRUJQVlA4IHIAAADQBACdASpBADEAPrVYpU0nJSOiKAgA4BaJZQDROBp5yAP+ZJAiyssALDJ5qcqqAAD+6Wke' .
			'EkOfff/5KfeVru2b5UEMRyftJP8tv7x1G6Ovctjfa9fH2k3kvmePT7SnobliibzTr6YIGAOMDWCY6kAAAAA=',
		'avif' =>
			'AAAAHGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZgAAAOptZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAA' .
			'AAAAAA5waXRtAAAAAAABAAAAImlsb2MAAAAAREAAAQABAAAAAAEOAAEAAAAAAAAAPAAAACNpaW5mAAAAAAABAAAAFWluZmUC' .
			'AAAAAAEAAGF2MDEAAAAAamlwcnAAAABLaXBjbwAAABNjb2xybmNseAABAA0ABoAAAAAMYXYxQ4EADAAAAAAUaXNwZQAAAAAA' .
			'AABBAAAAMQAAABBwaXhpAAAAAAMICAgAAAAXaXBtYQAAAAAAAAABAAEEAYIDBAAAAERtZGF0EgAKCRgZYGDBAQ0GhDItRQAB' .
			'RRRQoLSA8V8fE9NVJw4K+5Dq3AbdGd0c4twC1z5n6qAw3C9fsqzZm4rL',
		'bmp' =>
			'Qk2KAgAAAAAAAD4AAAAoAAAAQQAAADEAAAABAAEAAAAAAEwCAADEDgAAxA4AAAIAAAACAAAAAAAAAP///wBKpNEqVVW1qoAA' .
			'AACSSgqCqqqtWoAAAAAkkVJUWrVVVoAAAABJKiSJVqqqqoAAAACSSUlSVVVq1QAAAAAkkpIlVVaqqoAAAABLJSVIqtVWqoAA' .
			'AACQSEiVWqqqqoAAAAAqkpMiVVVVWoAAAABJKShUqqqq1oAAAACSSkqJVW2qqoAAAAAkkpJSW1VtVQAAAABKRKSkqqqqqoAA' .
			'AACSqQlJVVVVaoAAAAAkklISVVVVVQAAAABJJKSkqqqqrYAAAACSSkpKq21VVQAAAAAokREkWqttqoAAAABLSqqRVVVVVQAA' .
			'AACQJEkqqqqqqoAAAAAlUpJEVVVVVQAAAABKiKSpVW1VtoAAAACQVQkStqraqoAAAAAqklKkVVVVVQAAAABJJKRKqqqqqoAA' .
			'AACSSSqSVVVVVQAAAAAlEkJErVaqqoAAAABIpRSpVbVWtQAAAACVSKkSqqq1rYAAAAAklUKkVVWqqgAAAABJJJSRVVVVVYAA' .
			'AACSSSkqqqqqtQAAAAAkkkpEWqqqqwAAAABJJJCpVtqtVQAAAACUkUUkqqtqqoAAAAAlLSpJVVVVqoAAAABJQKSWVVVVWoAA' .
			'AACSVQkgqqqqqoAAAAAkklJVWqqqqoAAAABJJKSKVtttqoAAAACSSkpQqqqqqoAAAAApEREVVVVVWoAAAABKqqqiVVVVVoAA' .
			'AACSSSSVVVVVVQAAAAAkkkkkWqqqqoAAAABJJJJJVttttYAAAACSSSSSqqqqqgAAAAAkkkkkVVVVVYAAAABJJJJJVVVVVQAA' .
			'AAA=',
		'tiff' =>
			'SUkqANIBAACAMgUDyBQSBwWEQeFQaGQmGwuHRGIROHxWJRUUKppRmNxqOR+PSGOyOQSSRSWUSeVSaWSmRxeYRSYxaZTWaTeZ' .
			'wWXTuVzyWz2gT+hT6czaizijUmkUufU2g06h0+pUKj1WlVal1iHVCuVOu1GwS6tVeyVmy1iv2mvWuw1Ox2a4W+5Qu1W27XW8' .
			'Rq53Gz32+Qy83e2YGoXvDX7D4DB4vBY264nIYjJDzCYzK46RZG/5q35fPZbGZzJ6KmaDMafPtLSavRxTU6/TUTW5vZ4nYajY' .
			'2zWbTeZ3c7jgY/a8Pe37b8ffxzd8vZ8jg8mV8zi9OZ87rabpdnjdDn93ZdTidrKdzr93xeeleX1brw+3wTf197f+j37byff5' .
			'S/3fTffn4uE+r9wE8b/Pw1L+QGtEDQW80Ewc6b/wYr8EQC3kIwKxsKQ0gcLw6zMHw2+EJRG08QwpD0MMLEEVr/FEURNFitxJ' .
			'FzoxjGzSxTGbQxvGECRpHKWR6+kfwvIUbyJHT9QrI0RSBJElSY6UnvjKMWSnJMqyW10kyfLMpS5MCqR5MaMTDH8vQfK8nL1M' .
			'kQzU8s0S0xU1zpFU5TjN8STi3c8yBPcBz650/zlQMGICAAoAAAEDAAEAAABBAAAAAQEDAAEAAAAxAAAAAgEDAAMAAABQAgAA' .
			'AwEDAAEAAAAFAAAABgEDAAEAAAACAAAAEQEEAAEAAAAIAAAAFQEDAAEAAAADAAAAFgEDAAEAAAAxAAAAFwEEAAEAAADJAQAA' .
			'HAEDAAEAAAABAAAAAAAAAAgACAAIAA==',
		'heic' =>
			'AAAAGGZ0eXBoZWljAAAAAG1pZjFoZWljAAABam1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAAA' .
			'DnBpdG0AAAAAAAEAAAAiaWxvYwAAAABEQAABAAEAAAAAAYoAAQAAAAAAAACkAAAAI2lpbmYAAAAAAAEAAAAVaW5mZQIAAAAA' .
			'AQAAaHZjMQAAAADqaXBycAAAAMtpcGNvAAAAd2h2Y0MBA3AAAAAAAAAAAAAe8AD8/fj4AAAPAyAAAQAYQAEMAf//A3AAAAMA' .
			'kAAAAwAAAwAeugJAIQABACtCAQEDcAAAAwCQAAADAAADAB6gJIEHJ5bq5Ka5uAhoMCAAAAMAIAAAAwAhIgABAAZEAcFzwYkA' .
			'AAAUaXNwZQAAAAAAAABCAAAAQAAAAChjbGFwAAAAQQAAAAEAAAAxAAAAAf////8AAAAC////8QAAAAIAAAAQcGl4aQAAAAAD' .
			'CAgIAAAAF2lwbWEAAAAAAAAAAQABBIECgwQAAACsbWRhdAAAAKAoAa8TRublMff/w40/6Z9P9dyZk6gxC/8z4+8Oqv8JcsQb' .
			'jp4n5SE/320/152IKNAvaxrU69lqpbV5rp50oH3A123pQOlSWQSJ+UqxHbut+lq7/uyf+5TiH+VOUIAIxqRfy4Eg4A/xGJx+' .
			'Ri5IUe/rPPI/6GlZHRBlpF5Aoz/47jrmdKM2Sl0sErQm7QVV3frXa6uodKhu56fz2dMIAAbU',
	];

	// 65 x 49 PNG of rgb(200, 60, 60) in Display P3, with the Display P3 profile (iCCP).
	private const COLOR_MANAGED =
		'iVBORw0KGgoAAAANSUhEUgAAAEEAAAAxCAIAAAAKt1PTAAABR2lDQ1BJQ0MgUHJvZmlsZQAAeJxjYGB8kJOcW8yiwMCQm1dS' .
		'FOTupBARGaXA/oiBmUGEgZOBj0E2Mbm4wDfYLYQBCIoTy4uTS4pyGFDAt2sMjCD6sm5GYl7KYXOrc+E/wk4v9L5RFb5aIJEB' .
		'P+BKSS1OBtJ/gFgpuaCohIGBUQHELi8pALFdgGyR5IzEFCA7AsjWKQI6EMhuAYmnQ9gzQOwkCHsNiF0UEuQMZB8AshXSkdhJ' .
		'SOzcnNJkqBtArudJzQsNBtJsQCzDUMwQwGAMDBN8apyB0ICBARRe6OFQnGZsBNHF48TAwHrv///PqgwM7JMZGP5O+P//98L/' .
		'//8uYmBgvsPAcCAPob/5PgOD7f7////vRoh57Wdg2GgODKadCDENCwYGQS4GhhM7CxKLIOHLDMRMaZkMDJ+WMzDwRjIwCF8A' .
		'6okGAFyVYfyowRAiAAAAQ0lEQVR42u3PAQ0AAAgDIDWX/Wcsc3yDBvTtVripfA4ODg4ODg4ODg4ODg4ODg4ODg4ODg4ODg4O' .
		'Dg4ODg4ODg4OmR45/gGiXIMkzAAAAABJRU5ErkJggg==';

	private const MULTI_FRAME = [
		'gif' =>
			'R0lGODlhQQAxAIEAAP8AAAAAAAAAAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAAQQAxAAAIXQABCBxIsKDB' .
			'gwgTKlzIsKHDhxAjSpxIsaLFixgzatzIsaPHjyBDihxJsqTJkyhTqlzJsqXLlzBjypxJs6bNmzhz6tzJs6fPn0CDCh1KtKjR' .
			'o0iTKl3KtKnTpxkDAgAh+QQBCgABACwAAAAAQQAxAIEAAP8AAAAAAAAAAAAIXQABCBxIsKDBgwgTKlzIsKHDhxAjSpxIsaLF' .
			'ixgzatzIsaPHjyBDihxJsqTJkyhTqlzJsqXLlzBjypxJs6bNmzhz6tzJs6fPn0CDCh1KtKjRo0iTKl3KtKnTpxkDAgA7',
		'png' =>
			'iVBORw0KGgoAAAANSUhEUgAAAEEAAAAxCAIAAAAKt1PTAAAACGFjVEwAAAACAAAAAPONk3AAAAAaZmNUTAAAAAAAAABBAAAA' .
			'MQAAAAAAAAAAAAEACgAA29B9KQAAAFVJREFUeJztzwENwCAAwDDAv2cwQfLyrAq2ucfz1tcBF/Rg6MHQg6EHQw+GHgw9GHow' .
			'9GDowdCDoQdDD4YeDD0YejD0YOjB0IOhB0MPhh4MPRj+8HAAs9kBYfiu+rIAAAAaZmNUTAAAAAEAAABBAAAAMQAAAAAAAAAA' .
			'AAEACgAAQKOX/QAAAFpmZEFUAAAAAnic7c8BDYAwEMDAZ/49byZIVsidgvaZ2fNx63bACzw0eGjw0OChwUODhwYPDR4aPDR4' .
			'aPDQ4KHBQ4OHBg8NHho8NHho8NDgocFDg4cGDw1/eDix2wFhu+AC4QAAAABJRU5ErkJggg==',
		'webp' =>
			'UklGRoQAAABXRUJQVlA4WAoAAAACAAAAQAAAMAAAQU5JTQYAAAAAAAAAAABBTk1GKAAAAAAAAAAAAEAAADAAAGQAAAJWUDhM' .
			'DwAAAC9AAAwABxD9j/4HIqL/AQBBTk1GKAAAAAAAAAAAAEAAADAAAGQAAABWUDhMDwAAAC9AAAwABxDR//4HIqL/AQA=',
		'tiff' =>
			'SUkqABYBAACAP8AACBQSBwWEQeFQaGQmGwuHRGIROHxWJRaKReNRmORiPRuPx2QSORSWQyeSSiTSmWSuXSqYS2Yy+ZTWaTeZ' .
			'zmbTqcTufT2gTyhT+h0GiUejUmi0ukUylU2oU+pU6qVGq1OrVmsVur12tV6uV+xWGyWCzWOz2W0Wu1W202+2XC3XG6XO7XK8' .
			'XW83e9X2+X+94G/YLAYPDYXEYTFYfF4nGY/HZHG5PIZTJZXMZfNZbOZnO5vPaHQaPP6XRabSafVanWajXavX63YbPZbXY7fa' .
			'bjbbnebvfbrgb3g7/hcXicfh8njcrkcvnc3oczpc/p9Hqdfrdnq9vsdztd3wd/xd7yeHy+Pzen0evz+3uwEKAAABAwABAAAA' .
			'QQAAAAEBAwABAAAAMQAAAAIBAwADAAAAlAEAAAMBAwABAAAABQAAAAYBAwABAAAAAgAAABEBBAABAAAACAAAABUBAwABAAAA' .
			'AwAAABYBAwABAAAAMQAAABcBBAABAAAADgEAABwBAwABAAAAAQAAALYCAAAIAAgACAAAAAAAAABJSSoAFgEAAIAAAA/4FBIH' .
			'BYRB4VBoZCYbC4dEYhE4fFYlFopF41GY5GI9G4/HZBI5FJZDJ5JKJNKZZK5dKphLZjL5lNZpN5nOZtOpxO59PaBPKFP6HQaJ' .
			'R6NSaLS6RTKVTahT6lTqpUarU6tWaxW6vXa1Xq5X7FYbJYLNY7PZbRa7VbbTb7ZcLdcbpc7tcrxdbzd71fb5f73gb9gsBg8N' .
			'hcRhMVh8XicZj8dkcbk8hlMllcxl81ls5mc7m89odBo8/pdFptJp9VqdZqNdq9frdhs9ltdjt9puNtud5u99uuBveDv+FxeJ' .
			'x+HyeNyuRy+dzehzOlz+n0ep1+t2er2+x3O13fB3/F3vJ4fL4/N6fR6/P7e7AQoAAAEDAAEAAABBAAAAAQEDAAEAAAAxAAAA' .
			'AgEDAAMAAAA0AwAAAwEDAAEAAAAFAAAABgEDAAEAAAACAAAAEQEEAAEAAACoAQAAFQEDAAEAAAADAAAAFgEDAAEAAAAxAAAA' .
			'FwEEAAEAAAAOAQAAHAEDAAEAAAABAAAAAAAAAAgACAAIAAAAAAAAAA==',
	];


	/**
	 * Return the still sample for a format.
	 *
	 * @param ImageFormat $format Format.
	 * @return string|null Encoded 65 x 49 sample, or null when none is shipped.
	 */
	public static function get(ImageFormat $format): ?string {
		$encoded = self::STILL[$format->value] ?? null;

		return $encoded === null ? null : (string)\base64_decode($encoded, true);
	}


	/**
	 * Return the color-management sample.
	 *
	 * @return string 65 x 49 PNG of rgb(200, 60, 60) in Display P3, with the Display P3 profile embedded.
	 */
	public static function colorManaged(): string {
		return (string)\base64_decode(self::COLOR_MANAGED, true);
	}


	/**
	 * Return the two-frame sample for a format (frame 0 red, frame 1 blue).
	 *
	 * @param ImageFormat $format Format.
	 * @return string|null Encoded 65 x 49 two-frame sample, or null when none is shipped.
	 */
	public static function multiFrame(ImageFormat $format): ?string {
		$encoded = self::MULTI_FRAME[$format->value] ?? null;

		return $encoded === null ? null : (string)\base64_decode($encoded, true);
	}


}

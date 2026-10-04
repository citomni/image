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

namespace CitOmni\Image\Exception;

/**
 * Thrown when supplied image content is rejected.
 *
 * Behavior:
 * - Signals a problem with the image content itself:
 *   1) Empty, unrecognized, corrupt, or truncated data
 *   2) A type outside the format vocabulary
 *   3) Dimensions above the image.max_pixels policy, before or after planning
 *   4) A multi-frame source when the call did not opt into first-frame use
 *   5) Decoded geometry that disagrees with the header
 *
 * Notes:
 * - Intended to be caught by adapters that accept untrusted images (uploads,
 *   remote fetches) and mapped to a validation response.
 * - A runtime that lacks a codec raises ImageCapabilityException instead.
 */
final class ImageInputException extends \RuntimeException {}

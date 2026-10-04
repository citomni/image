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
 * Thrown when the runtime cannot perform a requested image operation.
 *
 * Behavior:
 * - Signals a missing or broken capability, not bad input:
 *   1) No available backend can decode the source format
 *   2) No available backend can encode a requested output format
 *   3) First-frame decoding of a multi-frame source is unsupported
 *   4) A codec produced output that violates the requested contract,
 *      e.g. wrong format or geometry after encoding
 *
 * Notes:
 * - The requested format is never substituted; the operation fails instead.
 * - Image::capabilities() reports what the current runtime can do.
 */
final class ImageCapabilityException extends \RuntimeException {}

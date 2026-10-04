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
 * Thrown when an output with "overwrite" => false finds its target occupied.
 *
 * Behavior:
 * - target() names the output whose target already exists.
 * - When raised before processing, committed() is empty.
 * - When raised while publishing (another process created the target after
 *   the early check), committed() lists exactly the targets already
 *   created. Because no-clobber outputs are published before replacing
 *   outputs, no existing file has been replaced in that case.
 *
 * Notes:
 * - The guarantee is enforced at publish time with an atomic create-if-absent
 *   (hard link), not only by the early existence check.
 */
final class ImageTargetExistsException extends ImageWriteException {

	private int|string $target;


	/**
	 * Create a target conflict.
	 *
	 * @param string $message Exception message.
	 * @param array<int|string, string> $committed Output key => path of targets written before the conflict.
	 * @param array<int|string, string> $uncommitted Output key => path of untouched targets.
	 * @param int|string $target Output key whose target exists.
	 * @param \Throwable|null $previous Underlying failure.
	 */
	public function __construct(string $message, array $committed, array $uncommitted, int|string $target, ?\Throwable $previous = null) {
		parent::__construct($message, $committed, $uncommitted, $previous);

		$this->target = $target;
	}


	/**
	 * Output key whose target already exists.
	 *
	 * @return int|string Output key.
	 */
	public function target(): int|string {
		return $this->target;
	}


}

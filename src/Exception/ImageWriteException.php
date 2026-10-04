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
 * Thrown when finished outputs cannot all be published to their targets.
 *
 * Behavior:
 * - committed() lists the outputs whose target files were created or
 *   replaced before the failure, keyed by output key.
 * - uncommitted() lists the outputs whose target files were left untouched.
 * - Remaining temporary files are removed before this is thrown. Removal is
 *   best effort; a file the filesystem refuses to unlink stays behind as
 *   ".{target}.{random}.tmp" and is safe to delete.
 *
 * Notes:
 * - Image::save() throws this (or ImageTargetExistsException) only about
 *   publishing targets. Any other exception from save() means no target
 *   file was touched.
 * - Designed for extension by ImageTargetExistsException.
 */
class ImageWriteException extends \RuntimeException {

	/** @var array<int|string, string> */
	private array $committed;

	/** @var array<int|string, string> */
	private array $uncommitted;


	/**
	 * Create a commit failure.
	 *
	 * @param string $message Exception message.
	 * @param array<int|string, string> $committed Output key => path of replaced targets.
	 * @param array<int|string, string> $uncommitted Output key => path of untouched targets.
	 * @param \Throwable|null $previous Underlying failure.
	 */
	public function __construct(string $message, array $committed, array $uncommitted, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);

		$this->committed = $committed;
		$this->uncommitted = $uncommitted;
	}


	/**
	 * Outputs written before the failure.
	 *
	 * @return array<int|string, string> Output key => target path.
	 */
	public function committed(): array {
		return $this->committed;
	}


	/**
	 * Outputs not written.
	 *
	 * @return array<int|string, string> Output key => target path.
	 */
	public function uncommitted(): array {
		return $this->uncommitted;
	}


}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use OCA\Social\Tools\Exceptions\ShellMissingCommandException;
use OCA\Social\Tools\Exceptions\ShellMissingItemException;
use OCA\Social\Tools\Exceptions\ShellUnknownCommandException;
use OCA\Social\Tools\Exceptions\ShellUnknownItemException;

/**
 * Interface IInteractiveShellClient
 *
 * @package OCA\Social\Tools
 */
interface IInteractiveShellClient {
	/**
	 * @param string $source
	 * @param string $field
	 *
	 * @return array
	 */
	public function fillCommandList(string $source, string $field): array;


	/**
	 * @param string $command
	 *
	 * @throws ShellMissingItemException
	 * @throws ShellUnknownCommandException
	 * @throws ShellUnknownItemException
	 * @throws ShellMissingCommandException
	 */
	public function manageCommand(string $command): void;
}

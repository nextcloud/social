<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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

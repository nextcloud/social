<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\Social\Migration;


use OCA\Social\Service\CheckService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;


/**
 * Class CheckInstallation
 *
 * @package OCA\Social\Migration
 */
class CheckInstallation implements IRepairStep {


	/** @var CheckService */
	protected $checkService;


	/**
	 * CheckInstallation constructor.
	 *
	 * @param CheckService $checkService
	 */
	public function __construct(CheckService $checkService) {
		$this->checkService = $checkService;
	}


	/**
	 * Returns the step's name
	 *
	 * @return string
	 * @since 9.1.0
	 */
	public function getName() {
		return 'Check the installation of the Social app.';
	}


	/**
	 * @param IOutput $output
	 */
	public function run(IOutput $output) {
		$this->checkService->checkInstallationStatus(true);
	}


}

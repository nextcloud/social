<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_instance")
 */
class Instance {
	/**
	 * @ORM\Id
	 * @ORM\Column
	 */
	private string $domain = "";

	/**
	 * @ORM\Column(type="int")
	 */
	private int $accountsCount = -1;

	public function getDomain(): string {
		return $this->domain;
	}

	public function setDomain(string $domain): void {
		$this->domain = $domain;
	}

	public function getAccountsCount(): int {
		return $this->accountsCount;
	}

	public function setAccountsCount(int $accountsCount): void {
		$this->accountsCount = $accountsCount;
	}
}

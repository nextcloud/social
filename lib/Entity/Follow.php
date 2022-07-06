<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_follow")
 */
class Follow {
	/**
	 * @ORM\Id
	 * @ORM\Column(type="bigint")
	 * @ORM\GeneratedValue
	 */
	private string $id = "-1";

	/**
	 * @ORM\Column(name="created_at")
	 */
    private DateTimeInterface $createdAt;

	/**
	 * @ORM\Column(name="updated_at")
	 */
    private DateTimeInterface $updatedAt;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(nullable=false)
	 */
    private Account $account;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(nullable=false)
	 */
	private Account $targetAccount;

	/**
	 * @ORM\Column
	 */
    private bool $showReblogs = true;

	/**
	 * @ORM\Column
	 */
	private string $uri = "";

	/**
	 * @ORM\Column
	 */
	private bool $notify = false;

	public function __construct() {
		$this->updatedAt = new \DateTime();
		$this->createdAt = new \DateTime();
		$this->account = new Account();
		$this->targetAccount = new Account();
	}
}

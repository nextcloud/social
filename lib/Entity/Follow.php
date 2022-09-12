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
	private ?string $id = null;

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
    private \DateTime $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
    private \DateTime $updatedAt;

	/**
	 * @ORM\ManyToOne(cascade={"persist", "remove"})
	 * @ORM\JoinColumn(nullable=false)
	 */
    private ?Account $account = null;

	/**
	 * @ORM\ManyToOne(cascade={"persist", "remove"})
	 * @ORM\JoinColumn(nullable=false)
	 */
	private ?Account $targetAccount = null;

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

	public function getId(): string {
		return $this->id;
	}

	public function getCreatedAt():\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTime $createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function getUpdatedAt(): \DateTimeInterface {
		return $this->updatedAt;
	}

	public function setUpdatedAt(\DateTime $updatedAt): void {
		$this->updatedAt = $updatedAt;
	}

	public function getAccount(): Account {
		return $this->account;
	}

	public function setAccount(Account $account): void {
		$this->account = $account;
	}

	public function getTargetAccount(): Account {
		return $this->targetAccount;
	}

	public function setTargetAccount(Account $targetAccount): void {
		$this->targetAccount = $targetAccount;
	}

	public function isShowReblogs(): bool {
		return $this->showReblogs;
	}

	public function setShowReblogs(bool $showReblogs): void {
		$this->showReblogs = $showReblogs;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): void {
		$this->uri = $uri;
	}

	public function isNotify(): bool {
		return $this->notify;
	}

	public function setNotify(bool $notify): void {
		$this->notify = $notify;
	}
}

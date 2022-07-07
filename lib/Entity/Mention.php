<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_mention")
 */
class Mention {
	/**
	 * @ORM\Id
	 * @ORM\Column(type="bigint")
	 * @ORM\GeneratedValue
	 */
	private string $id = "";

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn()
	 */
	private ?Status $status = null;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn()
	 */
	private ?Account $account = null;

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
	private \DateTimeInterface $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
	private \DateTimeInterface $updatedAt;

	/**
	 * @ORM\Column
	 */
	private bool $silent = false;

	public function __construct() {
		$this->createdAt = new \DateTime();
		$this->updatedAt = new \DateTime();
	}

	public function getId(): string {
		return $this->id;
	}

	public function getStatus(): ?Status {
		return $this->status;
	}

	public function setStatus(?Status $status): void {
		$this->status = $status;
	}

	public function getAccount(): ?Account {
		return $this->account;
	}

	public function setAccount(?Account $account): void {
		$this->account = $account;
	}

	public function getCreatedAt() {
		return $this->createdAt;
	}

	public function setCreatedAt($createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function getUpdatedAt() {
		return $this->updatedAt;
	}

	public function setUpdatedAt($updatedAt): void {
		$this->updatedAt = $updatedAt;
	}
}

<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\ORM\Mapping as ORM;
use OCP\IURLGenerator;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_media_attachment")
 */
class MediaAttachment  {
	const TYPE_IMAGE = 1;

	public const IMAGE_MIME_TYPES = [
		'image/png',
		'image/jpeg',
		'image/jpg',
		'image/gif',
		'image/x-xbitmap',
		'image/x-ms-bmp',
		'image/bmp',
		'image/svg+xml',
		'image/webp',
	];

	public const IMAGE_MIME_TYPES_CONVERSATION = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/jpg' => 'jpg',
		'image/gif' => 'gif',
		'image/x-xbitmap' => 'bmp',
		'image/x-ms-bmp' => 'bmp',
		'image/bmp' => 'bmp',
		'image/svg+xml' => 'svg',
		'image/webp' => 'webp',
	];

	/**
	 * @ORM\Id
	 * @ORM\Column(type="bigint")
	 * @ORM\GeneratedValue
	 */
	private ?string $id = '-1';

	/**
	 * @ORM\ManyToOne
	 */
	private ?Status $status = null;

	/**
	 * @ORM\Column(name="remote_url", nullable=false)
	 */
	private string $remoteUrl = "";

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
	private \DateTime $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
	private \DateTime $updatedAt;

	/**
	 * @ORM\Column
	 */
	private ?string $shortcode = null;

	/**
	 * @ORM\Column
	 */
	private string $mimetype = 'image/png';

	/**
	 * @ORM\Column(type="text")
	 */
	private string $description = '';

	/**
	 * @ORM\Column
	 */
	private int $type = self::TYPE_IMAGE;

	/**
	 * @ORM\Column(type="json")
	 */
	private ?array $meta;

	/**
	 * @ORM\ManyToOne
	 */
	private ?Account $account = null;

	/**
	 * @ORM\Column
	 */
	private string $blurhash = '';

	public function __construct() {
		$this->updatedAt = new \DateTime();
		$this->createdAt = new \DateTime();
		$this->meta = [];
	}

	static public function create(): self {
		$attachement = new MediaAttachment();
		$length = 14;
		$length = ($length < 4) ? 4 : $length;
		$attachement->setShortcode(bin2hex(random_bytes(($length - ($length % 2)) / 2)));
		return $attachement;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setId(?string $id): void {
		$this->id = $id;
	}

	public function getStatus(): ?Status {
		return $this->status;
	}

	public function setStatus(?Status $status): void {
		$this->status = $status;
	}

	public function getRemoteUrl(): string {
		return $this->remoteUrl;
	}

	public function setRemoteUrl(string $remoteUrl): void {
		$this->remoteUrl = $remoteUrl;
	}

	public function getCreatedAt(): \DateTime {
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTime $createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function getUpdatedAt(): \DateTime {
		return $this->updatedAt;
	}

	public function setUpdatedAt(\DateTime $updatedAt): void {
		$this->updatedAt = $updatedAt;
	}

	public function getShortcode(): ?string {
		return $this->shortcode;
	}

	public function setShortcode(?string $shortcode): void {
		$this->shortcode = $shortcode;
	}

	public function getType(): int {
		return $this->type;
	}

	public function setType(int $type): void {
		$this->type = $type;
	}

	public function getMeta(): ?array {
		return $this->meta;
	}

	public function setMeta(?array $meta): void {
		$this->meta = $meta;
	}

	public function getAccount(): ?Account {
		return $this->account;
	}

	public function setAccount(?Account $account): void {
		$this->account = $account;
	}

	public function getBlurhash(): ?string {
		return $this->blurhash;
	}

	public function setBlurhash(?string $blurhash): void {
		$this->blurhash = $blurhash;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function setDescription(?string $description): void {
		$this->description = $description;
	}

	public function getMimetype(): string {
		return $this->mimetype;
	}

	public function setMimetype(string $mimetype): void {
		$this->mimetype = $mimetype;
	}

	function toMastodonApi(IURLGenerator $generator) {
		return [
			'id' => $this->getId(),
			'url' => $generator->getAbsoluteURL('/apps/social/media/' . $this->getShortcode() . '.'),
			'preview_url' => $generator->getAbsoluteURL('/apps/social/media/' . $this->getShortcode() . '.' . self::IMAGE_MIME_TYPES_CONVERSATION[$this->getMimetype()]),
			'remote_url' => null,
			'text_url' => $generator->getAbsoluteURL('/apps/social/media/' . $this->getShortcode()),
			'description' => $this->getDescription(),
			'meta' => $this->getMeta(),
		];
	}
}

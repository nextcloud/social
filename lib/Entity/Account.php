<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use OCA\DAV\CalDAV\Principal\Collection;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_account")
 */
class Account {
	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 */
    private int $id;

	/**
	 * @ORM\Column(name="user_name", type="string", nullable=false)
	 */
	private string $userName;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(name="domain", referencedColumnName="domain", nullable=false)
	 */
	private Instance $instance;

	/**
	 * @ORM\Column(name="private_key", type="text", nullable=false)
	 */
	private string $privateKey;

	/**
	 * @ORM\Column(name="public_key", type="text", nullable=false)
	 */
	private string $publicKey;

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
	private \DateTime $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
	private \DateTime $updatedAt;

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $uri = "";

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $url;

	/**
	 * @ORM\Column(type="boolean", nullable=false)
	 */
	private bool $locked = false;

	/**
	 * @ORM\Column(name="avatar_remote_url", type="string", nullable=false)
	 */
	private string $avatarRemoteUrl = "";

	/**
	 * @ORM\Column(name="header_remote_url", type="string", nullable=false)
	 */
	private string $headerRemoteUrl = "";

	/**
	 * @ORM\Column(name="last_webfingered_at", type="datetime", nullable=true)
	 */
	private ?\DateTimeInterface $lastWebfingeredAt = null;

	/**
	 * @ORM\Column(name="inbox_url", type="string", nullable=false)
	 */
	private string $inboxUrl = "";

	/**
	 * @ORM\Column(name="outbox_url", type="string", nullable=false)
	 */
	private string $outboxUrl = "";

	/**
	 * @ORM\Column(name="shared_inbox_url", type="string", nullable=false)
	 */
	private string $sharedInboxUrl = "";

	/**
	 * @ORM\Column(name="followers_url", type="string", nullable=false)
	 */
	private string $followersUrl = "";

	/**
	 * @ORM\Column(name="protocol", type="string", nullable=false)
	 */
	private string $protocol = "ostatus";

	/**
	 * @ORM\Column(type="boolean", nullable=false)
	 */
	private bool $memorial = false;

	/**
	 * @ORM\Column(type="json", nullable=false)
	 */
	private array $fields = [];

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	private ?string $actorType = null;

	/**
	 * @ORM\Column(type="boolean", nullable=true)
	 */
	private ?bool $discoverable = null;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY")
	 * @var Collection<Follow>
	 */
	private Collection $follow;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY")
	 * @var Collection<Follow>
	 */
	private Collection $followedBy;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY")
	 * @var Collection<Block>
	 */
	private Collection $block;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY")
	 * @var Collection<Block>
	 */
	private Collection $blockedBy;

#  avatar_file_name              :string
#  avatar_content_type           :string
#  avatar_file_size              :integer
#  avatar_updated_at             :datetime
#  header_file_name              :string
#  header_content_type           :string
#  header_file_size              :integer
#  header_updated_at             :datetime
#  avatar_remote_url             :string
#  locked                        :boolean          default(FALSE), not null
#  header_remote_url             :string           default(""), not null
#  last_webfingered_at           :datetime
#  inbox_url                     :string           default(""), not null
#  outbox_url                    :string           default(""), not null
#  shared_inbox_url              :string           default(""), not null
#  followers_url                 :string           default(""), not null
#  protocol                      :integer          default("ostatus"), not null
#  memorial                      :boolean          default(FALSE), not null
#  moved_to_account_id           :bigint(8)
#  featured_collection_url       :string
#  fields                        :jsonb
#  actor_type                    :string
#  discoverable                  :boolean
#  also_known_as                 :string           is an Array
#  silenced_at                   :datetime
#  suspended_at                  :datetime
#  hide_collections              :boolean
#  avatar_storage_schema_version :integer
#  header_storage_schema_version :integer
#  devices_url                   :string
#  suspension_origin             :integer
#  sensitized_at                 :datetime
#  trendable                     :boolean
#  reviewed_at                   :datetime
#  requested_review_at           :datetime

}

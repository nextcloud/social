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

namespace OCA\Social\Model\ActivityPub;


use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TPathTools;
use daita\MySmallPhpTools\Traits\TStringTools;
use JsonSerializable;
use OCA\Social\Exceptions\ActivityCantBeVerifiedException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\LinkedDataSignature;


class ACore extends Item implements JsonSerializable {


	use TArrayTools;
	use TStringTools;
	use TPathTools;


	const CONTEXT_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';
	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	const AS_ID = 1;
	const AS_TYPE = 2;
	const AS_URL = 3;
	const AS_DATE = 4;
	const AS_USERNAME = 5;
	const AS_ACCOUNT = 6;
	const AS_STRING = 7;
	const AS_TAGS = 10;

	const FORMAT_ACTIVITYPUB = 1;
	const FORMAT_LOCAL = 2;


	/** @var null Item */
	private $parent = null;

	/** @var string */
	private $requestToken = '';

	/** @var array */
	private $entries = [];

	/** @var ACore */
	private $object = null;

	/** @var Document */
	private $icon = null;

	/** @var bool */
	private $displayW3ContextSecurity = false;

	/** @var LinkedDataSignature */
	private $signature = null;

	/** @var int */
	private $format = self::FORMAT_ACTIVITYPUB;


	/**
	 * Core constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		if ($parent instanceof ACore) {
			$this->setParent($parent);
		}
	}


	/**
	 * @return string
	 */
	public function getRequestToken(): string {
		if ($this->isRoot()) {
			return $this->requestToken;
		} else {
			return $this->getRoot()
						->getRequestToken();
		}
	}

	/**
	 * @param string $token
	 *
	 * @return ACore
	 */
	public function setRequestToken(string $token): ACore {
		$this->requestToken = $token;

		return $this;
	}


	/**
	 * @param ACore $parent
	 *
	 * @return ACore
	 */
	public function setParent(ACore $parent): ACore {
		$this->parent = $parent;

		return $this;
	}

	/**
	 * @return ACore
	 */
	public function getParent(): ACore {
		return $this->parent;
	}


	/**
	 * @return bool
	 */
	public function hasObject(): bool {
		if ($this->object === null) {
			return false;
		}

		return true;
	}

	/**
	 * @return ACore
	 */
	public function getObject(): ACore {
		return $this->object;
	}

	/**
	 * @param ACore $object
	 *
	 * @return ACore
	 */
	public function setObject(ACore &$object): ACore {
		$object->setParent($this);
		$this->object = $object;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getObjectId(): string {
		if ($this->hasObject()) {
			return $this->getObject()
						->getId();
		}

		return parent::getObjectId();
	}


	/**
	 * @param bool $filter - will remove general url like Public
	 *
	 * @return array
	 */
	public function getRecipients(bool $filter = false): array {
		$recipients = array_merge($this->getToAll(), $this->getCcArray());

		if (!$filter) {
			return $recipients;
		}

		return array_diff($recipients, [self::CONTEXT_PUBLIC]);
	}


	/**
	 * @return bool
	 */
	public function hasIcon(): bool {
		if ($this->icon === null) {
			return false;
		}

		return true;
	}

	/**
	 * @return Document
	 */
	public function getIcon(): Document {
		return $this->icon;
	}

	/**
	 * @param Document $icon
	 *
	 * @return ACore
	 */
	public function setIcon(Document &$icon): ACore {
		$icon->setParent($this);
		$this->icon = $icon;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isDisplayW3ContextSecurity(): bool {
		return $this->displayW3ContextSecurity;
	}

	/**
	 * @param bool $display
	 *
	 * @return ACore
	 */
	public function setDisplayW3ContextSecurity(bool $display): ACore {
		$this->displayW3ContextSecurity = $display;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isPublic(): bool {
		return in_array(self::CONTEXT_PUBLIC, $this->getRecipients());
	}


	/**
	 * @return bool
	 */
	public function gotSignature(): bool {
		return ($this->signature !== null);
	}

	/**
	 * @return LinkedDataSignature
	 */
	public function getSignature(): LinkedDataSignature {
		return $this->signature;
	}

	/**
	 * @param LinkedDataSignature $signature
	 *
	 * @return ACore
	 */
	public function setSignature(LinkedDataSignature $signature): Acore {
		$this->signature = $signature;

		return $this;
	}


	/**
	 * @param string $base
	 * @param bool $root
	 *
	 * @throws UrlCloudException
	 */
	public function generateUniqueId(string $base = '', bool $root = true) {
		$url = '';
		if ($root) {
			$url = $this->getUrlCloud();
			if ($url === '') {
				throw new UrlCloudException();
			}
		}

		if ($base !== '') {
			$base = $this->withoutEndSlash($this->withBeginSlash($base));
		}

		$this->setId($url . $base . '/' . $this->uuid());
	}


	/**
	 * @param string $id
	 *
	 * @throws InvalidOriginException
	 */
	public function checkOrigin(string $id) {
		$host = parse_url($id, PHP_URL_HOST);
		$origin = $this->getRoot()
					   ->getOrigin();

		if ($id !== '' && $origin === $host && $host !== '') {
			return;
		}

		throw new InvalidOriginException(
			'ACore::checkOrigin - id: ' . $id . ' - origin: ' . $origin
		);
	}


	/**
	 * @param string $url
	 *
	 * @throws ActivityCantBeVerifiedException
	 * @deprecated
	 *
	 */
	public function verify(string $url) {
		// TODO - Compare this with checkOrigin() - and delete this method.
		$url1 = parse_url($this->getId());
		$url2 = parse_url($url);

		if ($this->get('host', $url1, '1') !== $this->get('host', $url2, '2')) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}

		if ($this->get('scheme', $url1, '1') !== $this->get('scheme', $url2, '2')) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}

		if ($this->getInt('port', $url1, 1) !== $this->getInt('port', $url2, 1)) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}
	}


	/**
	 * @return bool
	 */
	public function isRoot(): bool {
		return ($this->parent === null);
	}

	/**
	 * @param array $chain
	 *
	 * @return ACore
	 */
	public function getRoot(array &$chain = []): ACore {
		$chain[] = $this;
		if ($this->isRoot()) {
			return $this;
		}

		return $this->getParent()
					->getRoot($chain);
	}


	/**
	 * @param array $arr
	 *
	 * @return ACore
	 */
	public function setEntries(array $arr): ACore {
		$this->entries = $arr;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return ACore
	 */
	public function addEntry(string $k, string $v): ACore {
		if ($v === '') {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return ACore
	 */
	public function addEntryInt(string $k, int $v): ACore {
		if ($v === 0) {
			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param bool $v
	 *
	 * @return ACore
	 */
	public function addEntryBool(string $k, bool $v): ACore {
		if ($v === 0) {
			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param array $v
	 *
	 * @return ACore
	 */
	public function addEntryArray(string $k, array $v): ACore {
		if ($v === []) {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param ACore $v
	 *
	 * @return ACore
	 */
	public function addEntryItem(string $k, ACore $v): ACore {
		if ($v === null) {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}


	/**
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	public function validate(int $as, string $k, array $arr, string $default = ''): string {
		try {
			return $this->validateEntryString($as, $this->get($k, $arr, $default));
		} catch (InvalidResourceEntryException $e) {
			return $default;
		}
	}


	/**
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param array $default
	 *
	 * @return array
	 */
	public function validateArray(int $as, string $k, array $arr, array $default = []): array {
		$values = $this->getArray($k, $arr, $default);

		$result = [];
		foreach ($values as $value) {
			try {
				if (is_array($value)) {
					$result[] = $this->validateEntryArray($as, $value);
				} else {
					$result[] = $this->validateEntryString($as, $value);
				}
			} catch (InvalidResourceEntryException $e) {
			}
		}

		return $result;
	}


	/**
	 * // TODO - better checks
	 *
	 * @param int $as
	 * @param string $value
	 * @param bool $exception
	 *
	 * @return string
	 * @throws InvalidResourceEntryException
	 */
	public function validateEntryString(int $as, string $value, bool $exception = true): string {
		switch ($as) {
			case self::AS_ID:
				if (parse_url($value) !== false) {
					return $value;
				}
				break;

			case self::AS_TYPE:
				return $value;

			case self::AS_URL:
				if (parse_url($value) !== false) {
					return $value;
				}
				break;

			case self::AS_DATE:
				return $value;

			case self::AS_STRING:
				$value = strip_tags($value);
				$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

				return $value;

			case self::AS_USERNAME:
				$value = strip_tags($value);

				return $value;

			case self::AS_ACCOUNT:
				$value = strip_tags($value);

				return $value;
		}

		if ($exception) {
			throw new InvalidResourceEntryException($as . ' ' . $value);
		} else {
			return '';
		}
	}


	/**
	 * @param int $as
	 * @param array $values
	 *
	 * @return array
	 * @throws InvalidResourceEntryException
	 */
	public function validateEntryArray(int $as, array $values): array {
		switch ($as) {
			case self::AS_TAGS:

				return [
					'type' => $this->validateEntryString(
						self::AS_TYPE, $this->get('type', $values, ''), false
					),
					'href' => $this->validateEntryString(
						self::AS_URL, $this->get('href', $values, ''), false
					),
					'name' => $this->validateEntryString(
						self::AS_STRING, $this->get('name', $values, ''), false
					)
				];
		}

		throw new InvalidResourceEntryException($as . ' ' . json_encode($values));
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setId($this->validate(self::AS_ID, 'id', $data, ''));
		$this->setType($this->validate(self::AS_TYPE, 'type', $data, ''));
		$this->setUrl($this->validate(self::AS_URL, 'url', $data, ''));
		$this->setSummary($this->get('summary', $data, ''));
		$this->setToArray($this->validateArray(self::AS_ID, 'to', $data, []));
		$this->setCcArray($this->validateArray(self::AS_ID, 'cc', $data, []));
		$this->setPublished($this->validate(self::AS_DATE, 'published', $data, ''));
		$this->setActorId($this->validate(self::AS_ID, 'actor', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object', $data, ''));
		$this->setTags($this->validateArray(self::AS_TAGS, 'tag', $data, []));
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		$this->setId($this->validate(self::AS_ID, 'id', $data, ''));
		$this->setType($this->validate(self::AS_TYPE, 'type', $data, ''));
		$this->setSubType($this->validate(self::AS_TYPE, 'subtype', $data, ''));
		$this->setUrl($this->validate(self::AS_URL, 'url', $data, ''));
		$this->setSummary($this->validate(self::AS_STRING, 'summary', $data, ''));
		$this->setTo($this->validate(self::AS_ID, 'to', $data, ''));
		$this->setToArray($this->validateArray(self::AS_ID, 'to_array', $data, []));
		$this->setCcArray($this->validateArray(self::AS_ID, 'cc', $data, []));
		$this->setBccArray($this->validateArray(self::AS_ID, 'bcc', $data, []));
		$this->setPublished($this->validate(self::AS_DATE, 'published', $data, ''));
		$this->setActorId($this->validate(self::AS_ID, 'actor_id', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setSource($this->get('source', $data, ''));
		$this->setLocal(($this->getInt('local', $data, 0) === 1));
	}


	/**
	 * @param int $format
	 *
	 * @return $this
	 */
	public function setExportFormat(int $format): self {
		$this->format = $format;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getExportFormat(): int {
		return $this->format;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		if ($this->getExportFormat() === self::FORMAT_LOCAL) {
			return $this->exportAsLocal();
		}

		return $this->exportAsActivityPub();
	}


	/**
	 * @return array
	 */
	public function exportAsActivityPub(): array {
		if ($this->gotSignature()) {
			$this->entries['signature'] = $this->getSignature();
		}

		if ($this->isRoot()) {
			$context = [self::CONTEXT_ACTIVITYSTREAMS];

			if ($this->gotSignature() || $this->isDisplayW3ContextSecurity()) {
				array_push($context, self::CONTEXT_SECURITY);
			}

			$this->addEntryArray('@context', $context);
		}

		$this->addEntry('id', $this->getId());
		$this->addEntry('type', $this->getType());
		$this->addEntry('subtype', $this->getSubType());
		$this->addEntry('url', $this->getUrl());
		$this->addEntry('to', $this->getTo());
		$this->addEntryArray('to', $this->getToArray());
		$this->addEntryArray('cc', $this->getCcArray());

		if ($this->hasActor()) {
			$this->addEntry(
				'actor', $this->getActor()
							  ->getId()
			);
			if ($this->isCompleteDetails()) {
				$this->addEntryItem('actor_info', $this->getActor());
			}
		} else {
			$this->addEntry('actor', $this->getActorId());
		}

		$this->addEntry('summary', $this->getSummary());
		$this->addEntry('published', $this->getPublished());
		$this->addEntryArray('tag', $this->getTags());

		if ($this->hasObject()) {
			$this->addEntryItem('object', $this->getObject());
		} else {
			$this->addEntry('object', $this->getObjectId());
		}

		// TODO - moving the $this->icon to Model/Person ?
		if ($this->hasIcon()) {
			$this->addEntryItem('icon', $this->getIcon());
		}

		if ($this->isCompleteDetails()) {
			$this->addEntry('source', $this->getSource());
		}

		if ($this->isLocal()) {
			$this->addEntryBool('local', $this->isLocal());
		}

		$result = $this->getEntries();
		$this->cleanArray($result);

		return $result;
	}


	/**
	 * @return array
	 */
	public function exportAsLocal(): array {
		return [
			"id"                     => "104745311773345735",
			"created_at"             => "2020-08-24T16=>29=>13.000Z",
			"in_reply_to_id"         => null,
			"in_reply_to_account_id" => null,
			"sensitive"              => false,
			"spoiler_text"           => "",
			"visibility"             => "unlisted",
			"language"               => "de",
			//			'id'               => $this->getId(),
			//			'uri'              => $this->getId(),
			//			'url'              => $this->getId(),
			'replies_count'          => 0,
			'reblogs_count'          => 0,
			'favourites_count'       => 0,
			'favourited'             => false,
			'reblogged'              => false,
			'muted'                  => false,
			'bookmarked'             => false,
			"content"                => "\u003cp\u003e\"Mit den frisch freigegebenen Versionen 3.0 und 3.13 aktualisiert Nextcloud seine Desktop- und Android-Clients. Zentrale Neuerung ist die nun für den produktiven Einsatz einsatzbereite Ende-zu-Ende-Verschlüsselung.\" | iX Magazin @heiseonline \u003cbr\u003e\u003ca href=\"https://www.heise.de/news/Neue-Nextcloud-Clients-mit-Ende-zu-Ende-Verschluesselung-4873632.html\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps://www.\u003c/span\u003e\u003cspan class=\"ellipsis\"\u003eheise.de/news/Neue-Nextcloud-C\u003c/span\u003e\u003cspan class=\"invisible\"\u003elients-mit-Ende-zu-Ende-Verschluesselung-4873632.html\u003c/span\u003e\u003c/a\u003e\u003c/p\u003e",
			"reblog"                 => null,
			"account"                => [
				"id"              => "126222",
				"username"        => "nextcloud",
				"acct"            => "nextcloud@mastodon.xyz",
				"display_name"    => "Nextcloud 📱☁️💻",
				"locked"          => false,
				"bot"             => false,
				"discoverable"    => false,
				"group"           => false,
				"created_at"      => "2017-05-02T09=>56=>41.951Z",
				"note"            => "\u003cp\u003eA safe home for all your data, community-driven \u0026amp; open source!\u003cbr\u003e\u003ca href=\"https=>//nextcloud.com\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps=>//\u003c/span\u003e\u003cspan class=\"\"\u003enextcloud.com\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e\u003cbr\u003eJoin us at \u003ca href=\"https=>//help.nextcloud.com\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps=>//\u003c/span\u003e\u003cspan class=\"\"\u003ehelp.nextcloud.com\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e and \u003ca href=\"https=>//github.com/nextcloud\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps=>//\u003c/span\u003e\u003cspan class=\"\"\u003egithub.com/nextcloud\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e 😊\u003c/p\u003e",
				"url"             => "https=>//mastodon.xyz/@nextcloud",
				"avatar"          => "https=>//files.mastodon.social/accounts/avatars/000/126/222/original/50785214e44d10cc.jpeg",
				"avatar_static"   => "https=>//files.mastodon.social/accounts/avatars/000/126/222/original/50785214e44d10cc.jpeg",
				"header"          => "https=>//files.mastodon.social/accounts/headers/000/126/222/original/6d7b41fdd92cfd6f.jpeg",
				"header_static"   => "https=>//files.mastodon.social/accounts/headers/000/126/222/original/6d7b41fdd92cfd6f.jpeg",
				"followers_count" => 9451,
				"following_count" => 132,
				"statuses_count"  => 3020,
				"last_status_at"  => "2020-08-24",
				"emojis"          => []
			]
			//      "fields": [
			//        {
			//			"name": "💻 Website",
			//          "value": "\u003ca href=\"https://nextcloud.com\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps://\u003c/span\u003e\u003cspan class=\"\"\u003enextcloud.com\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e",
			//          "verified_at": null
			//        },
			//        {
			//			"name": "😍 Contribute",
			//          "value": "\u003ca href=\"https://nextcloud.com/contribute\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps://\u003c/span\u003e\u003cspan class=\"\"\u003enextcloud.com/contribute\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e",
			//          "verified_at": null
			//        },
			//        {
			//			"name": "🌈 Include initiative",
			//          "value": "\u003ca href=\"https://nextcloud.com/include\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps://\u003c/span\u003e\u003cspan class=\"\"\u003enextcloud.com/include\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e",
			//          "verified_at": null
			//        },
			//        {
			//			"name": "💬 Forum",
			//          "value": "\u003ca href=\"https://help.nextcloud.com\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"\u003e\u003cspan class=\"invisible\"\u003ehttps://\u003c/span\u003e\u003cspan class=\"\"\u003ehelp.nextcloud.com\u003c/span\u003e\u003cspan class=\"invisible\"\u003e\u003c/span\u003e\u003c/a\u003e",
			//          "verified_at": null
			//        }
			//      ]
			//    }

		];

//    "media_attachments": [],
//    "mentions": [],
//    "tags": [],
//    "emojis": [],
//    "card": {
//			"url": "https://www.heise.de/news/Neue-Nextcloud-Clients-mit-Ende-zu-Ende-Verschluesselung-4873632.html",
//      "title": "Neue Nextcloud-Clients mit Ende-zu-Ende-Verschlüsselung",
//      "description": "Nextcloud aktualisiert seine Desktop- und Android-Clients. Zentrale Neuerung ist die Ende-zu-Ende-Verschlüsselung, die aber nicht für alle Dateien gedacht ist.",
//      "type": "link",
//      "author_name": "",
//      "author_url": "",
//      "provider_name": "",
//      "provider_url": "",
//      "html": "",
//      "width": 400,
//      "height": 225,
//      "image": "https://files.mastodon.social/cache/preview_cards/images/023/249/328/original/3710f7a1b54f0319.png",
//      "embed_url": "",
//      "blurhash": "UbNwWf_3%L00Rkayt6ofRkIVjYxut7fkoej["
//    },
//    "poll": null
//  },
	}

}



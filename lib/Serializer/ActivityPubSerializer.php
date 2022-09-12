<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Serializer;

/**
 * @template T
 */
abstract class ActivityPubSerializer {
	/**
	 * @param T $account
	 * @return array
	 */
	abstract public function toJsonLd(object $account): array;

	protected function getContext(): array {
		// Provide namespace information
		return [
			"@context" => [
				"https://www.w3.org/ns/activitystreams",
				"https://w3id.org/security/v1",
				[
					"manuallyApprovesFollowers" => "as:manuallyApprovesFollowers",
					"toot" => "http://joinmastodon.org/ns#",
					"featured" => [
						"@id" => "toot:featured",
						"@type" => "@id",
					],
					"featuredTags" => [
						"@id" => "toot:featuredTags",
						"@type" => "@id",
					],
					"alsoKnownAs" => [
						"@id" => "as:alsoKnownAs",
						"@type" => "@id",
					],
					"movedTo" => [
						"@id" => "as=>movedTo",
						"@type" => "@id"
					],
					"schema" => "http=>//schema.org#",
					"PropertyValue" => "schema:PropertyValue",
					"value" => "schema:value",
					"discoverable" => "toot:discoverable",
					"Device" => "toot:Device",
					"Ed25519Signature" => "toot:Ed25519Signature",
					"Ed25519Key" => "toot:Ed25519Key",
					"Curve25519Key" => "toot:Curve25519Key",
					"EncryptedMessage" => "toot:EncryptedMessage",
					"publicKeyBase64" => "toot:publicKeyBase64",
					"deviceId" => "toot:deviceId",
					"claim" => [
						"@type" => "@id",
						"@id" => "toot:claim"
					],
					"fingerprintKey" => [
						"@type" => "@id",
						"@id" => "toot:fingerprintKey"
					],
					"identityKey" => [
						"@type" => "@id",
						"@id" => "toot:identityKey"
					],
					"devices" => [
						"@type" => "@id",
						"@id" => "toot:devices"
					],
					"messageFranking" => "toot:messageFranking",
					"messageType" => "toot:messageType",
					"cipherText" => "toot:cipherText",
					"suspended" => "toot:suspended",
					"focalPoint" => [
						"@container" => "@list",
						"@id" => "toot:focalPoint"
					]
				]
			]
		];
	}
}

<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\ActivityPub;

use OCA\Social\Entity\Account;
use OCA\Social\Service\AccountFinder;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Model\Uri;

class SignedRequest extends Request {
	private ?Account $account = null;

	const FORMAT_URI = 'URI';
	const FORMAT_ACCOUNT = 'ACCOUNT';

	public const DATE_HEADER = 'D, d M Y H:i:s T';
	public const DATE_OBJECT = 'Y-m-d\TH:i:s\Z';

	private bool $alreadySigned = false;

	public function __construct(Uri $url, int $type = 0, bool $binary = false) {
		parent::__construct($url, $type, $binary);
	}

	/**
	 * @param self::FORMAT_* $keyIdFormat
	 */
	public function setOnBehalfOf(Account $onBehalfOf): self {
		$this->account = $onBehalfOf;
		return $this;
	}

	public function setKeyIdFormat(string $keyIdFormat = self::FORMAT_URI): self {
		$this->format = $keyIdFormat;
		return $this;
	}

	public function sign() {
		if ($this->alreadySigned) {
			throw new \RuntimeException('Trying to sign a request two times');
		}
		$date = gmdate(self::DATE_HEADER);

		$headersElements = ['(request-target)', 'content-length', 'date', 'host', 'digest'];
		$allElements = [
			'(request-target)' => Request::method($this->getType()) . ' ' . $this->getPath(),
			'date' => $date,
			'host' => $this->getHost(),
			'digest' => $this->generateDigest($this->getDataBody()),
			'content-length' => strlen($this->getDataBody())
		];

		$signing = $this->generateHeaders($headersElements, $allElements);
		openssl_sign($signing, $signed, $this->account->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$signature = $this->generateSignature($headersElements, $this->account->getUserName(), $signed);

		$this->addHeader('Signature', $signature);
	}

	private function generateHeaders(array $elements, array $data): string {
		$signingElements = [];
		foreach ($elements as $element) {
			$signingElements[] = $element . ': ' . $data[$element];
			$this->addHeader($element, $data[$element]);
		}

		return implode("\n", $signingElements);
	}

	private function generateSignature(array $elements, string $actorId, string $signed): string {
		$signatureElements[] = 'keyId="' . $actorId . '#main-key"';
		$signatureElements[] = 'algorithm="rsa-sha256"';
		$signatureElements[] = 'headers="' . implode(' ', $elements) . '"';
		$signatureElements[] = 'signature="' . $signed . '"';

		return implode(',', $signatureElements);
	}

	private function generateDigest(string $data): string {
		$encoded = hash("sha256", utf8_encode($data), true);

		return 'SHA-256=' . base64_encode($encoded);
	}
}

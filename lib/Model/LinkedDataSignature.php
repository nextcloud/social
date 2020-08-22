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


namespace OCA\Social\Model;


use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Service\SignatureService;


/**
 * Class LinkedDataSignature
 *
 * @package OCA\Social\Model
 */
class LinkedDataSignature implements JsonSerializable {


	use TArrayTools;

	/** @var string */
	private $type = '';

	/** @var string */
	private $creator = '';

	/** @var string */
	private $created = '';

	/** @var string */
	private $nonce = '';

	/** @var string */
	private $signatureValue = '';

	/** @var string */
	private $privateKey = '';

	/** @var string */
	private $publicKey = '';

	/** @var array */
	private $object = [];


	/**
	 * LinkedDataSignature constructor.
	 */
	public function __construct() {
	}


	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @param string $type
	 *
	 * @return LinkedDataSignature
	 */
	public function setType(string $type): LinkedDataSignature {
		$this->type = $type;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getCreator(): string {
		return $this->creator;
	}

	/**
	 * @param string $creator
	 *
	 * @return LinkedDataSignature
	 */
	public function setCreator(string $creator): LinkedDataSignature {
		$this->creator = $creator;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getNonce(): string {
		return $this->nonce;
	}

	/**
	 * @param string $nonce
	 *
	 * @return LinkedDataSignature
	 */
	public function setNonce(string $nonce): LinkedDataSignature {
		$this->nonce = $nonce;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getCreated(): string {
		return $this->created;
	}

	/**
	 * @param string $created
	 *
	 * @return LinkedDataSignature
	 */
	public function setCreated(string $created): LinkedDataSignature {
		$this->created = $created;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSignatureValue(): string {
		return $this->signatureValue;
	}

	/**
	 * @param string $signatureValue
	 *
	 * @return LinkedDataSignature
	 */
	public function setSignatureValue(string $signatureValue): LinkedDataSignature {
		$this->signatureValue = $signatureValue;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getObject(): array {
		return $this->object;
	}

	/**
	 * @param array $object
	 *
	 * @return LinkedDataSignature
	 */
	public function setObject(array $object): LinkedDataSignature {
		$this->object = $object;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	/**
	 * @param string $privateKey
	 *
	 * @return LinkedDataSignature
	 */
	public function setPrivateKey(string $privateKey): LinkedDataSignature {
		$this->privateKey = $privateKey;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPublicKey(): string {
		return $this->publicKey;
	}

	/**
	 * @param string $publicKey
	 *
	 * @return LinkedDataSignature
	 */
	public function setPublicKey(string $publicKey): LinkedDataSignature {
		$this->publicKey = $publicKey;

		return $this;
	}


	/**
	 * @throws LinkedDataSignatureMissingException
	 */
	public function sign() {
		$header = [
			'@context' => 'https://w3id.org/identity/v1',
			'creator'  => $this->getCreator(),
			'created'  => $this->getCreated()
		];

		$hash = $this->hashedCanonicalize($header) . $this->hashedCanonicalize($this->getObject());

		$algo = OPENSSL_ALGO_SHA256;
		if ($this->getType() === 'RsaSignature2017') {
			$algo = OPENSSL_ALGO_SHA256;
		}

		if (!openssl_sign($hash, $signed, $this->getPrivateKey(), $algo)) {
			throw new LinkedDataSignatureMissingException();
		}

		$this->setSignatureValue(base64_encode($signed));
	}


	/**
	 * @return bool
	 */
	public function verify(): bool {

		$header = [
			'@context' => 'https://w3id.org/identity/v1',
			'nonce'    => $this->getNonce(),
			'creator'  => $this->getCreator(),
			'created'  => $this->getCreated()
		];

		$hashHeader = $this->hashedCanonicalize($header, true);
		$hashObject = $this->hashedCanonicalize($this->getObject());

		$algo = OPENSSL_ALGO_SHA256;
		if ($this->getType() === 'RsaSignature2017') {
			$algo = OPENSSL_ALGO_SHA256;
		}

		$signed = base64_decode($this->getSignatureValue());
		if ($signed !== false
			&& openssl_verify(
				   $hashHeader . $hashObject, $signed, $this->getPublicKey(), $algo
			   ) === 1) {
			return true;
		}

		return false;
	}

	/**
	 * @param array $data
	 *
	 * @param bool $removeEmptyValue
	 *
	 * @return string
	 */
	private function hashedCanonicalize(array $data, bool $removeEmptyValue = false): string {
		if ($removeEmptyValue) {
			$this->cleanArray($data);
		}

		$object = json_decode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$res = jsonld_normalize(
			$object,
			[
				'algorithm'      => 'URDNA2015',
				'format'         => 'application/nquads',
				'documentLoader' => [SignatureService::class, 'documentLoader']
			]
		);

		return hash('sha256', $res);
	}


	/**
	 * @param array $data
	 *
	 * @throws LinkedDataSignatureMissingException
	 */
	public function import(array $data) {

//		if (!in_array(ACore::CONTEXT_SECURITY, $this->getArray('@context', $data, []))) {
//			throw new LinkedDataSignatureMissingException('no @context security entry');
//		}

		$signature = $this->getArray('signature', $data, []);
		if ($signature === []) {
			throw new LinkedDataSignatureMissingException('missing signature');
		}

		$this->setType($this->get('type', $signature, ''));
		$this->setCreator($this->get('creator', $signature, ''));
		$this->setNonce($this->get('nonce', $signature, ''));
		$this->setCreated($this->get('created', $signature, ''));
		$this->setSignatureValue($this->get('signatureValue', $signature, ''));

		unset($data['signature']);

		$this->setObject($data);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'type'           => $this->getType(),
			'creator'        => $this->getCreator(),
			'created'        => $this->getCreated(),
			'signatureValue' => $this->getSignatureValue()
		];
	}

}


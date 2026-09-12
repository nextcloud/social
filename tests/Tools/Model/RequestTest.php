<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Model\SimpleDataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {
	public function testDefaultsAreAnHttpsGetWithATenSecondTimeout(): void {
		$request = new Request('/inbox');

		$this->assertSame(['https'], $request->getProtocols());
		$this->assertSame(Request::TYPE_GET, $request->getType());
		$this->assertSame('/inbox', $request->getPath());
		$this->assertSame(10, $request->getTimeout());
		$this->assertTrue($request->isVerifyPeer());
		$this->assertTrue($request->isFollowLocation());
		$this->assertFalse($request->isBinary());
		$this->assertFalse($request->isHttpErrorsAllowed());
		$this->assertSame(0, $request->getPort());
	}

	public function testConstructorStoresTypeAndBinaryFlag(): void {
		$request = new Request('/media', Request::TYPE_POST, true);

		$this->assertSame(Request::TYPE_POST, $request->getType());
		$this->assertTrue($request->isBinary());
	}

	public function testBasedOnAnAbsoluteUrlSplitsProtocolHostPortAndPath(): void {
		$request = new Request('/inbox');

		$request->basedOnUrl('http://mastodon.local:8080/users/alice');

		$this->assertSame(['http'], $request->getProtocols());
		$this->assertSame('http', $request->getUsedProtocol());
		$this->assertSame('mastodon.local', $request->getHost());
		$this->assertSame(8080, $request->getPort());
		$this->assertSame('/users/alice/inbox', $request->getPath());
		$this->assertSame('http://mastodon.local:8080/users/alice/inbox', $request->getCompleteUrl());
		$this->assertSame('mastodon.local:8080', $request->getInstance());
	}

	public function testBasedOnAHostWithoutSchemeKeepsTheDefaultProtocol(): void {
		$request = new Request('/inbox');

		$request->basedOnUrl('mastodon.social:8443/base');

		$this->assertSame(['https'], $request->getProtocols());
		$this->assertSame('mastodon.social', $request->getHost());
		$this->assertSame(8443, $request->getPort());
		$this->assertSame('/base/inbox', $request->getPath());

		$plain = new Request('/inbox');
		$plain->basedOnUrl('mastodon.social');
		$this->assertSame('mastodon.social', $plain->getHost());
		$this->assertSame(0, $plain->getPort());
		$this->assertSame('/inbox', $plain->getPath());
	}

	public function testSetInstanceSplitsHostAndPort(): void {
		$request = new Request();

		$request->setInstance('mastodon.social:8443');
		$this->assertSame('mastodon.social', $request->getHost());
		$this->assertSame(8443, $request->getPort());

		$request->setInstance('other.example');
		$this->assertSame('other.example', $request->getHost());
		$this->assertSame('other.example:8443', $request->getInstance(), 'the port is kept');
	}

	public function testParamsAreSubstitutedIntoThePath(): void {
		$request = new Request('/users/:name/statuses/:id');
		$request->setHost('mastodon.social')->setUsedProtocol('https');

		$request->addParam('name', 'alice')->addParamInt('id', 42);

		$this->assertSame('/users/alice/statuses/:id', $request->getParametersUrl(), 'only string params are substituted');
		$this->assertSame('https://mastodon.social/users/alice/statuses/:id', $request->getCompleteUrl());
		$this->assertSame(['name' => 'alice', 'id' => 42], $request->getParams());
	}

	public function testDataIsSubstitutedIntoTheDeprecatedParsedUrl(): void {
		$request = new Request('/users/:name');

		$request->addData('name', 'alice')->addDataInt('page', 2);

		$this->assertSame('/users/alice', $request->getParsedUrl());
		$this->assertSame('{"name":"alice","page":2}', $request->getDataBody());
	}

	public function testDataCanBeSetFromJsonOrASerializableObject(): void {
		$request = new Request();

		$request->setDataJson('{"type":"Follow","actor":"https://a.example/users/alice"}');
		$this->assertSame(['type' => 'Follow', 'actor' => 'https://a.example/users/alice'], $request->getData());

		$request->setDataSerialize(new SimpleDataStore(['type' => 'Undo']));
		$this->assertSame(['type' => 'Undo'], $request->getData());
	}

	public function testQueryStringDuplicatesKeysForArraysByDefault(): void {
		$request = new Request();
		$request->setParams(['a' => '1', 'b' => ['x', 'y']]);

		$this->assertSame(Request::QS_VAR_DUPLICATE, $request->getQueryStringType());
		$this->assertSame('?a=1&b=x&b=y', $request->getQueryString());

		$request->setQueryStringType(Request::QS_VAR_ARRAY);
		$this->assertSame('?a=1&b%5B0%5D=x&b%5B1%5D=y', $request->getQueryString());

		$this->assertSame('', (new Request())->getQueryString());
	}

	public function testDeprecatedUrlParamsAndUrlDataUseEmptyBrackets(): void {
		$request = new Request();
		$request->setParams(['b' => ['x', 'y']]);
		$request->setData(['c' => ['z']]);

		$this->assertSame('b%5B%5D=x&b%5B%5D=y', $request->getUrlParams());
		$this->assertSame('c%5B%5D=z', $request->getUrlData());
		$this->assertSame('', (new Request())->getUrlParams());
		$this->assertSame('', (new Request())->getUrlData());
	}

	public function testHeadersAreMergedAndPrefixedWithTheUserAgent(): void {
		$request = new Request();
		$request->setUserAgent('Nextcloud Social');

		$request->addHeader('Accept', 'application/activity+json')
			->addHeader('Accept', 'application/ld+json')
			->addHeader('Date', 'Wed, 01 May 2024 12:00:00 GMT');

		$this->assertSame([
			'user-agent' => 'Nextcloud Social',
			'Accept' => 'application/activity+json, application/ld+json',
			'Date' => 'Wed, 01 May 2024 12:00:00 GMT',
		], $request->getHeaders());

		$request->setHeaders(['Signature' => 'x']);
		$this->assertSame(['user-agent' => 'Nextcloud Social', 'Signature' => 'x'], $request->getHeaders());
	}

	public function testProtocolSettersReplaceTheList(): void {
		$request = new Request();

		$request->setProtocol('http');
		$this->assertSame(['http'], $request->getProtocols());

		$request->setProtocols(['https', 'http']);
		$this->assertSame(['https', 'http'], $request->getProtocols());
	}

	public function testDeprecatedAddressAliasesTheHost(): void {
		$request = new Request();

		$request->setAddress('mastodon.social');

		$this->assertSame('mastodon.social', $request->getHost());
		$this->assertSame('mastodon.social', $request->getAddress());
	}

	public static function typeProvider(): array {
		return [
			'GET' => ['get', Request::TYPE_GET, 'get'],
			'POST' => ['POST', Request::TYPE_POST, 'post'],
			'PUT' => ['Put', Request::TYPE_PUT, 'put'],
			'DELETE' => ['delete', Request::TYPE_DELETE, 'delete'],
		];
	}

	#[DataProvider('typeProvider')]
	public function testTypeAndMethodMapBetweenNamesAndConstants(string $name, int $type, string $method): void {
		$this->assertSame($type, Request::type($name));
		$this->assertSame($method, Request::method($type));
	}

	public function testUnknownTypeNamesFallBackToGetAndUnknownConstantsToEmpty(): void {
		$this->assertSame(Request::TYPE_GET, Request::type('PATCH'));
		$this->assertSame('', Request::method(99));
	}

	public function testJsonSerializeDescribesTheRequest(): void {
		$request = new Request('/inbox', Request::TYPE_POST);
		$request->basedOnUrl('https://mastodon.social/users/alice');
		$request->setUserAgent('ua')
			->setTimeout(3)
			->setConnectTimeout(2)
			->addHeader('Accept', 'application/activity+json')
			->setCookies(['session' => 'x'])
			->addParam('page', '1')
			->addData('type', 'Follow')
			->setVerifyPeer(false)
			->setFollowLocation(false)
			->setHttpErrorsAllowed(true)
			->setResultCode(202)
			->setContentType('application/json');

		$this->assertSame([
			'protocols' => ['https'],
			'used_protocol' => 'https',
			'port' => 0,
			'host' => 'mastodon.social',
			'url' => '/users/alice/inbox',
			'timeout' => 3,
			'connectTimeout' => 2,
			'type' => Request::TYPE_POST,
			'cookies' => ['session' => 'x'],
			'headers' => ['user-agent' => 'ua', 'Accept' => 'application/activity+json'],
			'params' => ['page' => '1'],
			'data' => ['type' => 'Follow'],
			'userAgent' => 'ua',
			'followLocation' => false,
			'verifyPeer' => false,
			'binary' => false,
		], $request->jsonSerialize());
		$this->assertTrue($request->isHttpErrorsAllowed());
		$this->assertSame(202, $request->getResultCode());
		$this->assertSame('application/json', $request->getContentType());
	}
}

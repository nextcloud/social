<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use Throwable;

/**
 * What this instance says about itself.
 *
 * `/api/v1/instance` is the first request every Mastodon client makes, and
 * what it answers decides whether the client will talk to this server at all —
 * so these routes are read by strangers, answer without a token, and describe
 * the server rather than anybody on it: its rules, the domains it blocks, its
 * policies, the languages it can translate between, and the oEmbed a link to
 * one of its posts unfurls into.
 *
 * A trait and not a controller of its own, for the reason `ApiMedia` gives.
 */
trait ApiInstance {
	/**
	 * Mastodon's V1::Instance entity — the first request every client makes.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/')]
	public function instance(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return Revalidation::byContent($this->request, new DataResponse($local, Http::STATUS_OK));
	}

	/**
	 * The instance's rules, as their own resource.
	 *
	 * They were already served *inside* the instance entity, out of the `rules`
	 * app value — so the data was here and the route a client reads it from was
	 * a 404. An instance that has set none answers `[]`, which is the truthful
	 * answer and not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/rules')]
	public function instanceRules(): DataResponse {
		return new DataResponse(
			$this->instanceService->getLocal(Stream::FORMAT_LOCAL)->getRules(),
			Http::STATUS_OK
		);
	}

	/**
	 * The instances this one has decided not to federate with.
	 *
	 * Mastodon publishes the deny list so that somebody choosing a server can
	 * see who it will not talk to. That is a disclosure decision rather than a
	 * lookup, so it is one an admin makes: with `publish_blocks` unset — the
	 * default — this answers `[]`, which is what an instance that has not opted
	 * in should say rather than refusing and inviting a client to guess.
	 *
	 * Only ever the deny list. In allow-list mode the same column holds the
	 * instances this server *does* talk to, and publishing that as a block list
	 * would be exactly backwards.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/domain_blocks')]
	public function instanceDomainBlocks(): DataResponse {
		if ($this->configService->getAppValue(ConfigService::SOCIAL_PUBLISH_BLOCKS) !== '1'
			|| $this->fediverseService->getAccessType() !== 'all_but') {
			return new DataResponse([], Http::STATUS_OK);
		}

		$blocks = [];
		foreach ($this->fediverseService->getListedAddresses() as $domain) {
			// `digest` is Mastodon's sha256 of the domain, `severity` the only
			// one this list has, and `comment` is not stored here
			$blocks[] = [
				'domain' => $domain,
				'digest' => hash('sha256', $domain),
				'severity' => 'suspend',
				'comment' => '',
			];
		}

		return new DataResponse($blocks, Http::STATUS_OK);
	}

	/**
	 * The long form of what this instance is, as Mastodon's
	 * `ExtendedDescription`.
	 *
	 * Taken from the `extended_description` app value, and falling back to the
	 * short description the instance entity already carries — an empty page
	 * where a server has written a description elsewhere is worse than
	 * repeating it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/extended_description')]
	public function instanceExtendedDescription(): DataResponse {
		$text = trim($this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION));
		$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse([
			'updated_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'content' => $text === '' ? $instance->getDescription() : $text,
		], Http::STATUS_OK);
	}

	/**
	 * The server's privacy policy.
	 *
	 * Nextcloud's own, from Theming, rather than one kept by this app: a
	 * server has one privacy policy, and a second one here would be a second
	 * answer to the same question. A server that has published none answers
	 * **404**, as Mastodon does — an empty document would read as a policy
	 * that says nothing.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/privacy_policy')]
	public function instancePrivacyPolicy(): DataResponse {
		$policy = $this->instanceService->privacyPolicy();
		if ($policy === null) {
			return new DataResponse(
				['error' => 'this server has published no privacy policy'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($policy, Http::STATUS_OK);
	}

	/** The server's terms of service — Nextcloud's legal notice. See above. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/terms_of_service')]
	public function instanceTermsOfService(): DataResponse {
		$terms = $this->instanceService->termsOfService();
		if ($terms === null) {
			return new DataResponse(
				['error' => 'this server has published no terms of service'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($terms, Http::STATUS_OK);
	}

	/**
	 * oEmbed for one of this server's own public posts.
	 *
	 * What it is for: a site that is handed the link to a post asks this to
	 * find out who wrote it and where, instead of scraping the page. Mastodon
	 * serves the same route.
	 *
	 * `type` is `link`, not Mastodon's `rich`. A rich response is an `<iframe>`
	 * and this app has no embed page to put in one — every post URL here opens
	 * the whole app. A consumer handed `link` shows an attributed link, which
	 * is true; one handed `rich` with a frame that renders an application
	 * would embed something nobody meant to publish.
	 *
	 * Public posts only, and only this server's: an unlisted or followers-only
	 * post is not something to hand to whoever asks, and a post of somebody
	 * else's is theirs to describe.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/oembed')]
	public function oembed(string $url = '', string $format = 'json'): DataResponse {
		try {
			if ($format !== '' && strtolower($format) !== 'json') {
				// the only format this serves; oEmbed says to answer 501 for
				// one it does not, rather than to answer JSON anyway
				return new DataResponse(
					['error' => 'only the json format is served'], Http::STATUS_NOT_IMPLEMENTED
				);
			}

			$post = $this->streamService->getStreamById(trim($url));
			if (!$post->isLocal() || $post->getVisibility() !== Stream::TYPE_PUBLIC) {
				throw new StreamNotFoundException('Stream not found');
			}

			$author = $this->cacheActorService->getFromId($post->getAttributedTo());
			$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

			return new DataResponse([
				'type' => 'link',
				'version' => '1.0',
				'author_name' => ($author->getDisplayName() !== '')
					? $author->getDisplayName() : $author->getPreferredUsername(),
				'author_url' => $author->getId(),
				'provider_name' => $instance->getTitle(),
				'provider_url' => $this->configService->getCloudUrl(),
				'cache_age' => 86400,
				'url' => $post->getId(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Which languages this server can translate between: for each language it
	 * translates from, the ones it translates to.
	 *
	 * Read from the translation provider rather than declared here, so it is
	 * the truth about this Nextcloud. An instance with no provider answers an
	 * empty object, which is the same thing `translation.enabled: false` says
	 * in `/api/v2/instance` — a client that reads either one stops offering
	 * the button.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/translation_languages')]
	public function instanceTranslationLanguages(): DataResponse {
		try {
			return new DataResponse(
				(object)$this->translationService->languages(), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's V2::Instance entity. Newer clients ask for this one first and
	 * fall back to v1 on a 404; answering it saves them the round trip.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/instance')]
	public function instanceV2(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return Revalidation::byContent($this->request, new DataResponse($local->asV2(), Http::STATUS_OK));
	}
}

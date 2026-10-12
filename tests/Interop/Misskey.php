<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The Misskey on the other side, read through Misskey's own API.
 *
 * Misskey has no Mastodon API: every call is a `POST /api/<endpoint>` with a
 * JSON body that carries the account's token as `i`. A Misskey note's `uri`
 * is the ActivityPub id of a note it holds from elsewhere — ours — and is
 * empty for its own, whose id is `https://<host>/notes/<id>`.
 */
class Misskey extends RestClient {
	public function __construct(
		string $baseUrl,
		private string $token,
	) {
		parent::__construct($baseUrl);
	}

	/** Whether this suite has a Misskey to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('MISSKEY_BASE_URL');
		$token = (string)getenv('MISSKEY_TOKEN');
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token);
	}

	#[\Override]
	public function name(): string {
		return 'Misskey';
	}

	#[\Override]
	protected function authorization(): string {
		return '';
	}

	/**
	 * One Misskey API call.
	 *
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function call(string $endpoint, array $body = []): array {
		return $this->request('POST', $this->url('/api/' . $endpoint), ['i' => $this->token] + $body);
	}

	/** The host Misskey's own accounts are named under. */
	public function host(): string {
		return (string)parse_url($this->baseUrl, PHP_URL_HOST);
	}

	/** `username@host` of the account the token belongs to. */
	public function handle(): string {
		return (string)($this->call('i')['username'] ?? '') . '@' . $this->host();
	}

	/** The ActivityPub id of one of Misskey's own notes. */
	public function noteUri(array $note): string {
		return (string)(($note['uri'] ?? '') !== '' ? $note['uri'] : $this->baseUrl . '/notes/' . $note['id']);
	}

	/**
	 * Misskey's id for one of our accounts, resolving it over the network —
	 * which proves our webfinger and actor document are ones Misskey takes.
	 */
	public function resolveUser(string $username, string $host): string {
		$user = $this->call('users/show', ['username' => $username, 'host' => $host]);
		$id = (string)($user['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('Misskey could not resolve ' . $username . '@' . $host . ': ' . json_encode($user));
		}

		return $id;
	}

	/**
	 * How the token's account stands to another.
	 *
	 * @return array<string, mixed>
	 */
	public function relation(string $userId): array {
		$relation = $this->call('users/relation', ['userId' => $userId]);

		return (is_array($relation[0] ?? null) ? $relation[0] : $relation)
			+ ['isFollowing' => false, 'isFollowed' => false, 'hasPendingFollowRequestFromYou' => false];
	}

	/**
	 * The notes Misskey holds for one account, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function notesOf(string $userId): array {
		return $this->listOf($this->call('users/notes', ['userId' => $userId, 'limit' => 40]));
	}

	/**
	 * Waits for a note of one account whose ActivityPub id is `$uri`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitNoteOf(string $userId, string $uri): ?array {
		return $this->await(function () use ($userId, $uri): ?array {
			foreach ($this->notesOf($userId) as $note) {
				if (($note['uri'] ?? '') === $uri) {
					return $note;
				}
			}

			return null;
		});
	}

	/** @return array<string, mixed>|null the note, or null once Misskey no longer has it */
	public function note(string $noteId): ?array {
		[$status, $answer] = $this->send(
			'POST', $this->url('/api/notes/show'),
			(string)json_encode(['i' => $this->token, 'noteId' => $noteId]),
			array_merge($this->headers(), ['Content-Type: application/json'])
		);
		if ($status === 400 || $status === 404) {
			return null;
		}
		if ($status >= 300) {
			throw new RuntimeException('Misskey notes/show answered ' . $status . ': ' . substr($answer, 0, 300));
		}
		$note = json_decode($answer, true);

		return is_array($note) ? $note : null;
	}

	/**
	 * Writes a note.
	 *
	 * @param array<string, mixed> $fields Misskey's `notes/create`
	 * @return array<string, mixed> the note as written
	 */
	public function publish(string $text, array $fields = []): array {
		$note = $this->call('notes/create', ['text' => $text, 'visibility' => 'public'] + $fields)['createdNote'] ?? null;
		if (!is_array($note) || ($note['id'] ?? '') === '') {
			throw new RuntimeException('Misskey did not write the note');
		}

		return $note;
	}

	/** Every reaction on a note, counted, whichever emoji it is. */
	public static function reactionCount(array $note): int {
		return array_sum(array_map('intval', is_array($note['reactions'] ?? null) ? $note['reactions'] : []));
	}
}

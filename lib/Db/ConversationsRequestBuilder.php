<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class ConversationsRequestBuilder
 *
 * Two kinds of statement live here, because a conversation in this app is two
 * things: a thread, which is derived from `social_stream` and stored nowhere,
 * and what one account has done with that thread — read it, dismissed it —
 * which is the only part that needs a row of its own.
 *
 * The thread statements select four columns and join nothing. They walk a
 * thread up and down, and the only questions they answer are which post is
 * whose parent and what its nid is; hydrating a Stream for each step would
 * read the whole post — content, attachments, actor — to look at one id.
 *
 * @package OCA\Social\Db
 */
class ConversationsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getConversationStateInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	protected function getConversationStateSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'cs.id', 'cs.actor_id', 'cs.actor_id_prim', 'cs.root_id', 'cs.root_id_prim',
			'cs.read_nid', 'cs.hidden_nid', 'cs.muted'
		)
			->from(self::TABLE_CONVERSATION_STATE, 'cs');

		$this->defaultSelectAlias = 'cs';
		$qb->setDefaultSelectAlias('cs');

		return $qb;
	}

	protected function getConversationStateUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	protected function getConversationStateDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	/**
	 * A post reduced to its place in a thread: who it answers, and the two ids
	 * it is addressed by.
	 */
	protected function getThreadLinkSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id', 's.id_prim', 's.nid', 's.in_reply_to')
			->from(self::TABLE_STREAM, 's');

		$this->defaultSelectAlias = 's';
		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * The same reduction, narrowed to the posts of a thread that were
	 * addressed to — or sent by — one account as a direct message.
	 *
	 * The join is against `social_stream_dest`, which is where
	 * `StreamRequest::getTimelineDirect()` reads the direct timeline from and
	 * which carries the author beside the recipients, so a thread the account
	 * only ever spoke in is theirs too. Being a `dm` dest of one post of the
	 * thread is what makes the conversation the account's own: it is the same
	 * predicate that put the post in their direct timeline in the first place.
	 */
	protected function getThreadLinkForDestSql(string $actorId): SocialQueryBuilder {
		$qb = $this->getThreadLinkSelectSql();
		$expr = $qb->expr();

		$qb->innerJoin(
			's', self::TABLE_STREAM_DEST, 'sd',
			$expr->andX(
				$expr->eq('sd.stream_id', 's.id_prim'),
				$expr->eq('sd.actor_id', $qb->createNamedParameter($qb->prim($actorId))),
				$expr->eq('sd.type', $qb->createNamedParameter('dm'))
			)
		);

		return $qb;
	}

	/**
	 * @return array{id: string, idPrim: string, nid: int, inReplyTo: string}
	 */
	protected function parseThreadLinkSelectSql(array $data): array {
		return [
			'id' => $this->get('id', $data),
			'idPrim' => $this->get('id_prim', $data),
			'nid' => $this->getInt('nid', $data),
			'inReplyTo' => $this->get('in_reply_to', $data),
		];
	}

	/**
	 * @return array{rootId: string, readNid: int, hiddenNid: int}
	 */
	protected function parseConversationStateSelectSql(array $data): array {
		return [
			'rootId' => $this->get('root_id', $data),
			'readNid' => $this->getInt('read_nid', $data),
			'hiddenNid' => $this->getInt('hidden_nid', $data),
		];
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/** Durable, one-use OAuth device authorization requests. */
class DeviceAuthorizationRequest extends CoreRequestBuilder {
	public function create(string $deviceHash, string $userCode, string $clientId, string $scopes, int $expires, int $created): void {
		$qb = $this->getQueryBuilder();
		$qb->insert('social_device_auth')
			->setValue('device_hash', $qb->createNamedParameter($deviceHash))
			->setValue('user_code', $qb->createNamedParameter($userCode))
			->setValue('client_id', $qb->createNamedParameter($clientId))
			->setValue('scopes', $qb->createNamedParameter($scopes))
			->setValue('status', $qb->createNamedParameter('pending'))
			->setValue('poll_interval', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT))
			->setValue('last_poll', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('expires', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT))
			->setValue('created', $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	public function byUserCode(string $userCode): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from('social_device_auth')
			->where($qb->expr()->eq('user_code', $qb->createNamedParameter($userCode)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $row;
	}

	public function byDeviceHash(string $deviceHash, string $clientId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from('social_device_auth')
			->where($qb->expr()->eq('device_hash', $qb->createNamedParameter($deviceHash)))
			->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $row;
	}

	public function setDecision(int $id, string $status, string $userId): bool {
		$qb = $this->getQueryBuilder();
		$affected = $qb->update('social_device_auth')
			->set('status', $qb->createNamedParameter($status))
			->set('user_id', $qb->createNamedParameter($userId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->executeStatement();

		return $affected === 1;
	}

	public function poll(int $id, int $now): array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from('social_device_auth')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		if ($row === false) {
			return ['error' => 'invalid_grant'];
		}
		$interval = (int)$row['poll_interval'];
		$outcome = self::pollOutcome((string)$row['status'], (int)$row['expires'], $interval, (int)$row['last_poll'], $now);
		if (($outcome['error'] ?? '') === 'expired_token') {
			return $outcome;
		}
		if (($outcome['error'] ?? '') === 'slow_down') {
			$next = $outcome['interval'];
			$update = $this->getQueryBuilder();
			$update->update('social_device_auth')
				->set('poll_interval', $update->createNamedParameter($next, IQueryBuilder::PARAM_INT))
				->set('last_poll', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return $outcome;
		}
		$update = $this->getQueryBuilder();
		$update->update('social_device_auth')
			->set('last_poll', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if (!($outcome['ready'] ?? false)) {
			return $outcome;
		}
		$consume = $this->getQueryBuilder();
		$affected = $consume->update('social_device_auth')
			->set('status', $consume->createNamedParameter('issued'))
			->where($consume->expr()->eq('id', $consume->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($consume->expr()->eq('status', $consume->createNamedParameter('allowed')))
			->executeStatement();
		if ($affected !== 1) {
			return ['error' => 'invalid_grant'];
		}

		return ['user_id' => (string)$row['user_id'], 'scopes' => (string)$row['scopes']];
	}

	/** @return array{error?: string, interval?: int, ready?: bool} */
	public static function pollOutcome(string $status, int $expires, int $interval, int $lastPoll, int $now): array {
		if ($expires <= $now) {
			return ['error' => 'expired_token'];
		}
		if ($lastPoll > 0 && $now - $lastPoll < $interval) {
			return ['error' => 'slow_down', 'interval' => $interval + 5];
		}
		return match ($status) {
			'pending' => ['error' => 'authorization_pending'],
			'denied' => ['error' => 'access_denied'],
			'allowed' => ['ready' => true],
			default => ['error' => 'invalid_grant'],
		};
	}

	public function prune(int $before): void {
		$qb = $this->getQueryBuilder();
		$qb->delete('social_device_auth')
			->where($qb->expr()->lt('expires', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Gives bob's follow of alice a second row beside the one seed.sh made, with
 * the other `accepted`: the same pair once pending and once accepted, which
 * the unique indexes of releases before 0.23 allowed and the upgrade has to
 * reduce to the accepted row before it can make the pair unique.
 *
 * Run from the server root, against the old release:
 *
 *   php path/to/tests/Upgrade/duplicate-follow.php
 */

require getcwd() . '/lib/base.php';

$db = \OCP\Server::get(\OCP\IDBConnection::class);

$qb = $db->getQueryBuilder();
$qb->select('*')
	->from('social_follow')
	->where($qb->expr()->eq('type', $qb->createNamedParameter('Follow')))
	->andWhere($qb->expr()->like('actor_id', $qb->createNamedParameter('%bob')))
	->andWhere($qb->expr()->like('object_id', $qb->createNamedParameter('%alice')));
$result = $qb->executeQuery();
$follow = $result->fetch();
$result->closeCursor();

if ($follow === false) {
	throw new RuntimeException('bob does not follow alice: run seed.sh first');
}

$accepted = in_array($follow['accepted'], [true, 1, '1', 't', 'true'], true);
$id = $follow['id'] . '#duplicate';
$insert = $db->getQueryBuilder();
$insert->insert('social_follow');
foreach ($follow as $column => $value) {
	$insert->setValue($column, $insert->createNamedParameter($value));
}
$insert->setValue('id', $insert->createNamedParameter($id))
	->setValue('id_prim', $insert->createNamedParameter(md5($id)))
	->setValue('accepted', $insert->createNamedParameter(!$accepted, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL));
$insert->executeStatement();

echo "bob's follow of alice now has a pending and an accepted row\n";

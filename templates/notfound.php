<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

// the shape of the server's own 404 page, so it reads as one
?>
<div class="body-login-container update">
	<div class="icon-big icon-search icon-white"></div>
	<h2><?php p($_['title']); ?></h2>
	<p class="infogroup"><?php p($_['message']); ?></p>
	<p>
		<a class="button primary" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkTo('', 'index.php')); ?>">
			<?php p($l->t('Back to %s', [\OCP\Server::get(\OCP\Defaults::class)->getName()])); ?>
		</a>
	</p>
</div>

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

/** @var \OCA\Social\Model\Report[] $reports */
$reports = $_['reports'];
$accessType = $_['accessType'];
$accessList = $_['accessList'];
?>

<div id="social-moderation" class="section">
	<h2><?php p($l->t('Reports')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Reports filed by the people on this instance and reports received from other instances.')); ?>
	</p>

	<?php if (empty($reports)): ?>
		<p><em><?php p($l->t('No reports.')); ?></em></p>
	<?php else: ?>
		<table class="grid social-reports">
			<thead>
				<tr>
					<th><?php p($l->t('Reported account')); ?></th>
					<th><?php p($l->t('Reporter')); ?></th>
					<th><?php p($l->t('Category')); ?></th>
					<th><?php p($l->t('Comment')); ?></th>
					<th><?php p($l->t('Statuses')); ?></th>
					<th><?php p($l->t('Date')); ?></th>
					<th><?php p($l->t('Status')); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($reports as $report): ?>
				<tr data-report-id="<?php p((string)$report->getId()); ?>">
					<td>
						<?php $target = $report->getTargetAccount(); ?>
						<?php if ($target !== null): ?>
							<a href="<?php p($target->getId()); ?>" target="_blank" rel="noreferrer noopener">
								<?php p($target->getAccount() !== '' ? $target->getAccount() : $target->getPreferredUsername()); ?>
							</a>
						<?php else: ?>
							<?php p($report->getAccountId()); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php p($report->getActorId()); ?>
						<?php if (!$report->isLocal()): ?>
							<em>(<?php p($l->t('remote')); ?>)</em>
						<?php endif; ?>
					</td>
					<td><?php p($report->getCategory()); ?></td>
					<td><?php p($report->getComment()); ?></td>
					<td>
						<?php foreach ($report->getStatusIds() as $statusId): ?>
							<?php if (str_starts_with($statusId, 'https://')): ?>
								<a href="<?php p($statusId); ?>" target="_blank" rel="noreferrer noopener">↗</a>
							<?php else: ?>
								<?php p($statusId); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
					<td><?php p($report->getCreation() > 0 ? gmdate('Y-m-d H:i', $report->getCreation()) : ''); ?></td>
					<td class="social-report-state">
						<?php p($report->isResolved() ? $l->t('Resolved') : $l->t('Open')); ?>
					</td>
					<td>
						<button type="button" class="social-report-toggle"
							data-resolved="<?php p($report->isResolved() ? '1' : '0'); ?>">
							<?php p($report->isResolved() ? $l->t('Reopen') : $l->t('Resolve')); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div id="social-access" class="section">
	<h2><?php p($l->t('Fediverse access')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Control which instances this server federates with. The same list is available through "occ social:fediverse".')); ?>
	</p>

	<p>
		<label for="social-access-type"><?php p($l->t('Access mode')); ?></label>
		<select id="social-access-type">
			<option value="all_but" <?php if ($accessType === 'all_but') {
				p('selected');
			} ?>>
				<?php p($l->t('Federate with every instance except the listed ones (blocklist)')); ?>
			</option>
			<option value="none_but" <?php if ($accessType === 'none_but') {
				p('selected');
			} ?>>
				<?php p($l->t('Federate only with the listed instances (allowlist)')); ?>
			</option>
		</select>
	</p>

	<ul id="social-access-list">
		<?php foreach ($accessList as $address): ?>
			<li data-address="<?php p($address); ?>">
				<?php p($address); ?>
				<button type="button" class="social-access-remove"><?php p($l->t('Remove')); ?></button>
			</li>
		<?php endforeach; ?>
	</ul>

	<p>
		<input type="text" id="social-access-address" placeholder="instance.example">
		<button type="button" id="social-access-add"><?php p($l->t('Add instance')); ?></button>
	</p>
</div>

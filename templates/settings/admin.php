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
$retentionDays = $_['retentionDays'];
$federation = $_['federation'];
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

<div id="social-retention" class="section">
	<h2><?php p($l->t('Retention')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Remote statuses older than this many days are deleted, unless a local user interacted with them, follows their author, or replied below them. Local content is never touched. 0 disables retention.')); ?>
	</p>
	<p>
		<label for="social-retention-days"><?php p($l->t('Keep remote statuses for (days)')); ?></label>
		<input type="number" id="social-retention-days" min="0" max="3650"
			value="<?php p((string)$retentionDays); ?>">
		<button type="button" id="social-retention-save"><?php p($l->t('Save')); ?></button>
	</p>
</div>

<div id="social-federation" class="section">
	<h2><?php p($l->t('Federation health')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Posts, follows and likes leave this server through a queue. A delivery that keeps failing is retried on a widening delay and then given up on, so an instance that has quietly stopped hearing from this one looks no different from one nobody has written to. This is where it shows.')); ?>
	</p>

	<p class="social-federation-counts">
		<?php p($l->n('%n delivery waiting to be sent.', '%n deliveries waiting to be sent.', $federation['waiting'])); ?>
		<?php if ($federation['running'] > 0): ?>
			<?php p($l->n('%n is being sent right now.', '%n are being sent right now.', $federation['running'])); ?>
		<?php endif; ?>
	</p>

	<?php if ($federation['failing'] === 0): ?>
		<p><em><?php p($l->t('Nothing is failing to deliver.')); ?></em></p>
	<?php else: ?>
		<p>
			<?php p($l->n(
				'%n delivery has failed at least once.',
				'%n deliveries have failed at least once.',
				$federation['failing']
			)); ?>
			<?php if ($federation['truncated']): ?>
				<?php p($l->t('(only the first few hundred were counted)')); ?>
			<?php endif; ?>
			<?php if ($federation['atRisk'] > 0): ?>
				<strong><?php p($l->n(
					'%n of them is close to being given up on.',
					'%n of them are close to being given up on.',
					$federation['atRisk']
				)); ?></strong>
			<?php endif; ?>
			<?php p($l->t('A delivery is abandoned after %s attempts.', [(string)$federation['maxTries']])); ?>
		</p>

		<table class="grid social-federation">
			<thead>
				<tr>
					<th><?php p($l->t('Instance')); ?></th>
					<th><?php p($l->t('Waiting deliveries')); ?></th>
					<th><?php p($l->t('Most attempts so far')); ?></th>
					<th><?php p($l->t('Last attempt')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($federation['instances'] as $instance): ?>
				<tr>
					<td><?php p($instance['host']); ?></td>
					<td><?php p((string)$instance['requests']); ?></td>
					<td><?php p($instance['tries'] . ' / ' . $federation['maxTries']); ?></td>
					<td><?php p($instance['last'] > 0 ? gmdate('Y-m-d H:i', $instance['last']) : $l->t('never')); ?></td>
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

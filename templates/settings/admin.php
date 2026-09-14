<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

/** @var \OCA\Social\Model\Report[] $reports the first page of the open ones */
$reports = $_['reports'];
$openReports = $_['openReports'];
$resolvedReports = $_['resolvedReports'];
$reportsPerPage = $_['reportsPerPage'];
/** @var array|null $server null for a delegate, who moderates but does not administer */
$server = $_['server'];
$accessType = $_['accessType'];
$accessList = $_['accessList'];
$retentionDays = $_['retentionDays'];
$federation = $_['federation'];
$moderation = $_['moderation'];

// Both report tables carry the same columns and the Javascript that appends a
// page builds the same row, so the heading lives in one array rather than in
// two copies that would drift apart the first time a column was added.
$reportColumns = [
	$l->t('Reported account'),
	$l->t('Reporter'),
	$l->t('Category'),
	$l->t('Comment'),
	$l->t('Statuses'),
	$l->t('Date'),
	$l->t('Status'),
	$l->t('Account'),
	'',
];

/** One row of a report table; see `reportRow()` in js/social-adminSettings.js. */
$reportRow = function (\OCA\Social\Model\Report $report) use ($l, $moderation): void {
	$target = $report->getTargetAccount();
	$targetId = $target !== null ? $target->getId() : $report->getAccountId();
	$level = $moderation[$targetId] ?? '';
	?>
	<tr data-report-id="<?php p((string)$report->getId()); ?>"
		data-actor-id="<?php p($targetId); ?>">
		<td>
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
		<td class="social-report-statuses">
			<?php foreach ($report->getStatusIds() as $statusId): ?>
				<span class="social-report-status" data-stream-id="<?php p($statusId); ?>">
					<?php if (str_starts_with($statusId, 'https://')): ?>
						<a href="<?php p($statusId); ?>" target="_blank" rel="noreferrer noopener">↗</a>
					<?php else: ?>
						<?php p($statusId); ?>
					<?php endif; ?>
					<button type="button" class="social-status-remove"
						title="<?php p($l->t('Take this post down')); ?>">
						<?php p($l->t('Take down')); ?>
					</button>
				</span>
			<?php endforeach; ?>
		</td>
		<td><?php p($report->getCreation() > 0 ? gmdate('Y-m-d H:i', $report->getCreation()) : ''); ?></td>
		<td class="social-report-state">
			<?php p($report->isResolved() ? $l->t('Resolved') : $l->t('Open')); ?>
		</td>
		<td class="social-moderation">
			<span class="social-moderation-state"><?php
				p($level === 'suspend' ? $l->t('Suspended') : ($level === 'silence' ? $l->t('Silenced') : ''));
	?></span>
			<button type="button" class="social-moderate" data-level="silence"
				<?php if ($level === 'silence') {
					p('disabled');
				} ?>><?php p($l->t('Silence')); ?></button>
			<button type="button" class="social-moderate" data-level="suspend"
				<?php if ($level === 'suspend') {
					p('disabled');
				} ?>><?php p($l->t('Suspend')); ?></button>
			<button type="button" class="social-moderate" data-level=""><?php p($l->t('Lift')); ?></button>
		</td>
		<td>
			<button type="button" class="social-report-toggle"
				data-resolved="<?php p($report->isResolved() ? '1' : '0'); ?>">
				<?php p($report->isResolved() ? $l->t('Reopen') : $l->t('Resolve')); ?>
			</button>
		</td>
	</tr>
	<?php
};
?>

<div id="social-moderation" class="section">
	<h2><?php p($l->t('Reports')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Reports filed by the people on this instance and reports received from other instances. The open ones are here; the ones somebody has already dealt with are below them, folded away.')); ?>
	</p>

	<p id="social-reports-none" <?php if ($openReports > 0) {
		p('hidden');
	} ?>><em><?php p($l->t('No open reports.')); ?></em></p>

	<table class="grid social-reports" id="social-reports-open" <?php if ($openReports === 0) {
		p('hidden');
	} ?>>
		<thead>
			<tr>
				<?php foreach ($reportColumns as $column): ?>
					<th><?php p($column); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($reports as $report) {
				$reportRow($report);
			} ?>
		</tbody>
	</table>

	<p class="social-reports-paging" data-per-page="<?php p((string)$reportsPerPage); ?>">
		<span id="social-reports-open-count" data-total="<?php p((string)$openReports); ?>">
			<?php p($l->n('%n open report.', '%n open reports.', $openReports)); ?>
		</span>
		<button type="button" id="social-reports-more" <?php if ($openReports <= $reportsPerPage) {
			p('hidden');
		} ?>><?php p($l->t('Show more')); ?></button>
	</p>

	<details id="social-reports-resolved-section" <?php if ($resolvedReports === 0) {
		p('hidden');
	} ?>>
		<summary><?php p($l->n('%n resolved report', '%n resolved reports', $resolvedReports)); ?></summary>

		<table class="grid social-reports" id="social-reports-resolved">
			<thead>
				<tr>
					<?php foreach ($reportColumns as $column): ?>
						<th><?php p($column); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody></tbody>
		</table>

		<p>
			<button type="button" id="social-reports-resolved-more" hidden><?php p($l->t('Show more')); ?></button>
		</p>
	</details>
</div>

<div id="social-accounts" class="section">
	<h2><?php p($l->t('Accounts')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Every account this instance knows, whether or not anybody has complained about it. Search by username, by handle, or by instance.')); ?>
	</p>

	<p class="social-accounts-search">
		<label for="social-accounts-query"><?php p($l->t('Search')); ?></label>
		<input type="search" id="social-accounts-query" placeholder="bob@instance.example">
		<label for="social-accounts-origin"><?php p($l->t('Origin')); ?></label>
		<select id="social-accounts-origin">
			<option value=""><?php p($l->t('Anywhere')); ?></option>
			<option value="local"><?php p($l->t('This instance')); ?></option>
			<option value="remote"><?php p($l->t('Other instances')); ?></option>
		</select>
		<label for="social-accounts-status"><?php p($l->t('State')); ?></label>
		<select id="social-accounts-status">
			<option value=""><?php p($l->t('Any')); ?></option>
			<option value="active"><?php p($l->t('Nothing standing against it')); ?></option>
			<option value="silenced"><?php p($l->t('Silenced')); ?></option>
			<option value="suspended"><?php p($l->t('Suspended')); ?></option>
		</select>
		<button type="button" id="social-accounts-search"><?php p($l->t('Search')); ?></button>
	</p>

	<p id="social-accounts-empty" hidden><em><?php p($l->t('No account matches that.')); ?></em></p>

	<table class="grid social-accounts" hidden>
		<thead>
			<tr>
				<th><?php p($l->t('Account')); ?></th>
				<th><?php p($l->t('Instance')); ?></th>
				<th><?php p($l->t('State')); ?></th>
				<th><?php p($l->t('History')); ?></th>
				<th><?php p($l->t('Decision')); ?></th>
			</tr>
		</thead>
		<tbody id="social-accounts-list"></tbody>
	</table>

	<p>
		<button type="button" id="social-accounts-more" hidden><?php p($l->t('Show more')); ?></button>
	</p>
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

<div id="social-announcements" class="section">
	<h2><?php p($l->t('Announcements')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('A notice every account on this instance is shown once in its client, until they dismiss it. Give it a start and an end and it is only shown between them — both or neither. An announcement that has run out stops being shown the moment it does; removing it here takes it away from everybody, read or not.')); ?>
	</p>

	<table class="grid social-announcements">
		<thead>
			<tr>
				<th><?php p($l->t('Announcement')); ?></th>
				<th><?php p($l->t('Shown from')); ?></th>
				<th><?php p($l->t('Until')); ?></th>
				<th><?php p($l->t('State')); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody id="social-announcements-list"></tbody>
	</table>

	<p>
		<label for="social-announcement-text"><?php p($l->t('New announcement')); ?></label><br>
		<textarea id="social-announcement-text" rows="3" cols="60"
			placeholder="<?php p($l->t('This server will be down for maintenance on Sunday.')); ?>"></textarea>
	</p>
	<p>
		<label for="social-announcement-starts"><?php p($l->t('From')); ?></label>
		<input type="datetime-local" id="social-announcement-starts">
		<label for="social-announcement-ends"><?php p($l->t('Until')); ?></label>
		<input type="datetime-local" id="social-announcement-ends">
		<label for="social-announcement-all-day">
			<input type="checkbox" id="social-announcement-all-day">
			<?php p($l->t('Whole days')); ?>
		</label>
		<button type="button" id="social-announcement-add"><?php p($l->t('Post announcement')); ?></button>
	</p>
</div>

<?php if ($server !== null): ?>
<div id="social-server" class="section">
	<h2><?php p($l->t('Server')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('What this instance tells other servers and their clients about itself, and the limits it holds them to. Every one of these could only be set with "occ config:app:set social" until now, which meant most of them were never set at all.')); ?>
	</p>

	<p>
		<label for="social-server-contact-email"><?php p($l->t('Contact address')); ?></label>
		<input type="email" id="social-server-contact-email" size="40"
			placeholder="admin@instance.example"
			value="<?php p($server['contact_email']); ?>">
		<em class="settings-hint"><?php p($l->t('Shown to anybody asking this server what it is. Clients read it on their first request.')); ?></em>
	</p>

	<p>
		<label for="social-server-extended-description"><?php p($l->t('About this instance')); ?></label><br>
		<textarea id="social-server-extended-description" rows="4" cols="60"
			placeholder="<?php p($l->t('Who runs this server, who it is for, and what is expected of the people on it.')); ?>"><?php p($server['extended_description']); ?></textarea>
	</p>

	<p>
		<label for="social-server-max-size"><?php p($l->t('Largest picture or file (MB)')); ?></label>
		<input type="number" id="social-server-max-size" min="1" max="10240"
			value="<?php p((string)$server['max_size']); ?>">
		<label for="social-server-max-video-size"><?php p($l->t('Largest video (MB)')); ?></label>
		<input type="number" id="social-server-max-video-size" min="1" max="102400"
			value="<?php p((string)$server['max_video_size']); ?>">
	</p>

	<p>
		<label for="social-server-inbox-throttle"><?php p($l->t('Inbox requests allowed per instance per minute')); ?></label>
		<input type="number" id="social-server-inbox-throttle" min="0" max="100000"
			value="<?php p((string)$server['inbox_throttle']); ?>">
		<em class="settings-hint"><?php p($l->t('0 accepts everything, which is what an instance behind its own rate limiter wants.')); ?></em>
	</p>

	<p>
		<label for="social-server-secure-mode">
			<input type="checkbox" id="social-server-secure-mode"
				<?php if ($server['secure_mode']) {
					p('checked');
				} ?>>
			<?php p($l->t('Secure mode')); ?>
		</label>
		<em class="settings-hint"><?php p($l->t('Unsigned ActivityPub fetches are refused. Servers that do not sign what they ask for — and every anonymous reader — stop seeing anything from this one.')); ?></em>
	</p>

	<p>
		<label for="social-server-publish-blocks">
			<input type="checkbox" id="social-server-publish-blocks"
				<?php if ($server['publish_blocks']) {
					p('checked');
				} ?>>
			<?php p($l->t('Publish the list of blocked instances')); ?>
		</label>
		<em class="settings-hint"><?php p($l->t('Anybody can then read which instances this server refuses, the way Mastodon publishes it.')); ?></em>
	</p>

	<p>
		<label for="social-server-allow-self-signed">
			<input type="checkbox" id="social-server-allow-self-signed"
				<?php if ($server['allow_self_signed']) {
					p('checked');
				} ?>>
			<?php p($l->t('Accept certificates that do not check out')); ?>
		</label>
		<strong><?php p($l->t('For development only.')); ?></strong>
		<em class="settings-hint"><?php p($l->t('On a server anybody else uses, this hands every federated request to whoever can answer for the address.')); ?></em>
	</p>

	<p>
		<button type="button" id="social-server-save"><?php p($l->t('Save')); ?></button>
		<span id="social-server-message" role="status"></span>
	</p>
</div>
<?php endif; ?>

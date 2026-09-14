<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Federation health')"
		:description="t('social', 'Posts, follows and likes leave this server through a queue. A delivery that keeps failing is retried on a widening delay and then given up on, so an instance that has quietly stopped hearing from this one looks no different from one nobody has written to. This is where it shows.')">
		<p class="federation__counts">
			{{ n('social', '%n delivery waiting to be sent.', '%n deliveries waiting to be sent.', federation.waiting) }}
			<template v-if="federation.running > 0">
				{{ n('social', '%n is being sent right now.', '%n are being sent right now.', federation.running) }}
			</template>
		</p>

		<NcNoteCard v-if="federation.failing === 0" type="success">
			{{ t('social', 'Nothing is failing to deliver.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard :type="federation.atRisk > 0 ? 'warning' : 'info'">
				{{ n('social', '%n delivery has failed at least once.', '%n deliveries have failed at least once.', federation.failing) }}
				<template v-if="federation.truncated">
					{{ t('social', '(only the first few hundred were counted)') }}
				</template>
				<strong v-if="federation.atRisk > 0">
					{{ n('social', '%n of them is close to being given up on.', '%n of them are close to being given up on.', federation.atRisk) }}
				</strong>
				{{ t('social', 'A delivery is abandoned after {attempts} attempts.', { attempts: federation.maxTries }) }}
			</NcNoteCard>

			<div class="social-admin__scroll">
				<table class="social-admin__table">
					<thead>
						<tr>
							<th>{{ t('social', 'Instance') }}</th>
							<th>{{ t('social', 'Waiting deliveries') }}</th>
							<th>{{ t('social', 'Most attempts so far') }}</th>
							<th>{{ t('social', 'Last attempt') }}</th>
						</tr>
					</thead>
					<!-- an instance name is a string a peer chose; it is
					     interpolated, never `v-html` -->
					<tbody>
						<tr v-for="instance in federation.instances" :key="instance.host">
							<td>{{ instance.host }}</td>
							<td>{{ instance.requests }}</td>
							<td>{{ instance.tries }} / {{ federation.maxTries }}</td>
							<td>{{ lastAttempt(instance.last) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<NcNoteCard v-if="federation.abandoned === 0" type="success">
			{{ n('social', 'Nothing has been given up on in the last %n day.', 'Nothing has been given up on in the last %n days.', federation.retentionDays) }}
		</NcNoteCard>
		<template v-else>
			<h3>{{ t('social', 'Given up on') }}</h3>
			<NcNoteCard type="error">
				<strong>
					{{ n('social', '%n delivery was given up on: that server never got it.', '%n deliveries were given up on: those servers never got them.', federation.abandoned) }}
				</strong>
				<template v-if="federation.abandonedTruncated">
					{{ t('social', '(only the first few hundred were counted)') }}
				</template>
				{{ n('social', 'Counted over the last %n day, which is how long a finished delivery is kept.', 'Counted over the last %n days, which is how long a finished delivery is kept.', federation.retentionDays) }}
			</NcNoteCard>

			<div class="social-admin__scroll">
				<table class="social-admin__table">
					<thead>
						<tr>
							<th>{{ t('social', 'Instance') }}</th>
							<th>{{ t('social', 'Deliveries given up on') }}</th>
							<th>{{ t('social', 'Most attempts so far') }}</th>
							<th>{{ t('social', 'Last attempt') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="instance in federation.givenUp" :key="instance.host">
							<td>{{ instance.host }}</td>
							<td>{{ instance.requests }}</td>
							<td>{{ instance.tries }} / {{ federation.maxTries }}</td>
							<td>{{ lastAttempt(instance.last) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="social-admin__hint">
				{{ t('social', 'Once the reason is fixed, "occ social:queue:retry --instance HOST" puts them back in the queue.') }}
			</p>
		</template>
	</NcSettingsSection>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

/**
 * What the outbound queue is doing.
 *
 * Read-only, and the only section whose whole content arrives with the page:
 * the summary is one pass over the queue and there is nothing here to write.
 */
export default {
	name: 'FederationSection',

	components: {
		NcNoteCard,
		NcSettingsSection,
	},

	props: {
		/** `FederationHealthService::summary()`, as initial state */
		federation: {
			type: Object,
			required: true,
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {number} last seconds since the epoch, or 0 for never
		 * @return {string} the moment, in UTC to the minute
		 */
		lastAttempt(last) {
			return last > 0
				? new Date(last * 1000).toISOString().slice(0, 16).replace('T', ' ')
				: t('social', 'never')
		},
	},
}
</script>

<style lang="scss" scoped>
.federation__counts {
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}
</style>

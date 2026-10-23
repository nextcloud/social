<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="discovery-health" aria-labelledby="discovery-health-title">
		<div class="discovery-health__heading">
			<h5 id="discovery-health-title">
				{{ t('social', 'Discovery source health') }}
			</h5>
			<NcButton variant="tertiary" :disabled="loading" @click="load">
				{{ t('social', 'Refresh') }}
			</NcButton>
		</div>
		<p class="discovery-health__hint">
			{{ t('social', 'Aggregated provider diagnostics for the last 24 hours. Search terms and reader identities are not stored.') }}
		</p>
		<NcLoadingIcon v-if="loading" :size="20" />
		<p v-else-if="!health">
			{{ t('social', 'Discovery health is currently unavailable.') }}
		</p>
		<div v-else class="discovery-health__sources">
			<article v-for="(source, provider) in health.sources" :key="provider" class="discovery-health__source">
				<h6>{{ providerLabel(provider) }}</h6>
				<p class="discovery-health__state" :class="`discovery-health__state--${source.state}`">
					{{ stateLabel(source.state) }}
				</p>
				<dl>
					<div><dt>{{ t('social', 'Requests') }}</dt><dd>{{ source.requests }}</dd></div>
					<div><dt>{{ t('social', 'Failed requests') }}</dt><dd>{{ source.failed_requests }}</dd></div>
					<div><dt>{{ t('social', 'Failure rate') }}</dt><dd>{{ formatRate(source.failure_rate) }}</dd></div>
					<div><dt>{{ t('social', 'Rate limited') }}</dt><dd>{{ source.rate_limited }}</dd></div>
					<div><dt>{{ t('social', 'Posts seen / stored') }}</dt><dd>{{ source.posts_seen }} / {{ source.posts_stored }}</dd></div>
					<div><dt>{{ t('social', 'Average discovery batch duration') }}</dt><dd>{{ source.average_duration_ms === null ? '—' : `${source.average_duration_ms} ms` }}</dd></div>
					<div><dt>{{ t('social', 'Last attempt') }}</dt><dd>{{ formatTimestamp(source.last_attempt) }}</dd></div>
					<div><dt>{{ t('social', 'Last successful request') }}</dt><dd>{{ formatTimestamp(source.last_success) }}</dd></div>
				</dl>
			</article>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { recommendationDiscoveryUrl } from '../../services/adminApi.js'
import { errorOf } from '../../utils/serverError.js'
import { showError } from '../../services/toast.js'

export default {
	name: 'DiscoveryHealth',
	components: { NcButton, NcLoadingIcon },
	data() {
		return { loading: false, health: null }
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(recommendationDiscoveryUrl())
				this.health = data
			} catch (error) {
				this.health = null
				showError(errorOf(error, t('social', 'Could not load discovery health')))
			} finally {
				this.loading = false
			}
		},

		providerLabel(provider) {
			return provider === 'atproto' ? t('social', 'Bluesky / ATProto') : t('social', 'Fediverse / ActivityPub')
		},

		stateLabel(state) {
			return {
				healthy: t('social', 'Healthy'),
				degraded: t('social', 'Degraded'),
				unavailable: t('social', 'Unavailable'),
				no_data: t('social', 'No recent data'),
			}[state] ?? t('social', 'Unknown')
		},

		formatTimestamp(value) {
			return value > 0 ? new Date(value * 1000).toLocaleString() : '—'
		},

		formatRate(value) {
			return Number.isFinite(value)
				? new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 1 }).format(value)
				: '—'
		},
	},
}
</script>

<style lang="scss" scoped>
.discovery-health {
	width: 100%;
	margin-block: calc(var(--default-grid-baseline) * 3);
	padding-block-start: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);

	&__heading {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 62ch;
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
	}

	&__sources {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
		gap: 12px;
	}

	&__source {
		padding: 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);

		h6,
		p {
			margin-block: 0 8px;
		}

		dl {
			margin: 0;
		}

		dl div {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding-block: 4px;
		}

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			text-align: end;
		}
	}

	&__state {
		font-weight: 600;
	}

	&__state--healthy {
		color: var(--color-success);
	}

	&__state--degraded {
		color: var(--color-warning);
	}

	&__state--unavailable {
		color: var(--color-error);
	}

	&__state--no_data {
		color: var(--color-text-maxcontrast);
	}
}
</style>

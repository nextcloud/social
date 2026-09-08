<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__blocked">
		<h2>{{ t('social', 'Blocked and muted accounts') }}</h2>
		<p class="social__blocked-hint">
			{{ t('social', 'Blocking severs the relationship in both directions and hides the account everywhere. Muting only hides it from you, without the account knowing.') }}
		</p>

		<section>
			<h3>{{ t('social', 'Blocked') }}</h3>
			<NcEmptyContent v-if="!loading && blocked.length === 0"
				:name="t('social', 'No blocked accounts')"
				:description="t('social', 'Accounts you block from their profile show up here.')">
				<template #icon>
					<Cancel />
				</template>
			</NcEmptyContent>
			<div v-for="account in blocked" :key="`block-${account.id}`" class="blocked-account">
				<div class="blocked-account__user">
					<NcAvatar :url="account.avatar" :disable-tooltip="true" />
					<router-link :to="{ name: 'profile', params: { account: account.acct } }">
						<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
						<span class="blocked-account__acct">{{ account.acct }}</span>
					</router-link>
				</div>
				<NcButton :disabled="busy.includes(account.id)"
					:aria-label="t('social', 'Unblock')"
					@click="unblock(account)">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ t('social', 'Unblock') }}
				</NcButton>
			</div>
		</section>

		<section>
			<h3>{{ t('social', 'Muted') }}</h3>
			<NcEmptyContent v-if="!loading && muted.length === 0"
				:name="t('social', 'No muted accounts')"
				:description="t('social', 'Accounts you mute from their profile show up here.')">
				<template #icon>
					<VolumeOff />
				</template>
			</NcEmptyContent>
			<div v-for="account in muted" :key="`mute-${account.id}`" class="blocked-account">
				<div class="blocked-account__user">
					<NcAvatar :url="account.avatar" :disable-tooltip="true" />
					<router-link :to="{ name: 'profile', params: { account: account.acct } }">
						<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
						<span class="blocked-account__acct">{{ account.acct }}</span>
					</router-link>
				</div>
				<NcButton :disabled="busy.includes(account.id)"
					:aria-label="t('social', 'Unmute')"
					@click="unmute(account)">
					<template #icon>
						<VolumeHigh :size="20" />
					</template>
					{{ t('social', 'Unmute') }}
				</NcButton>
			</div>
		</section>

		<div v-if="loading" class="loading-indicator">
			{{ t('social', 'Loading…') }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import logger from '../services/logger.js'

export default {
	name: 'BlockedAccounts',
	components: {
		NcAvatar,
		NcButton,
		NcEmptyContent,
		Cancel,
		VolumeHigh,
		VolumeOff,
	},
	data() {
		return {
			/** @type {import('../types/Mastodon.js').Account[]} */
			blocked: [],
			/** @type {import('../types/Mastodon.js').Account[]} */
			muted: [],
			busy: [],
			loading: true,
		}
	},
	async mounted() {
		await this.fetchAll()
	},
	methods: {
		async fetchAll() {
			this.loading = true
			try {
				const [blocked, muted] = await Promise.all([
					axios.get(generateUrl('apps/social/api/v1/blocks')),
					axios.get(generateUrl('apps/social/api/v1/mutes')),
				])
				this.blocked = Array.isArray(blocked.data) ? blocked.data : []
				this.muted = Array.isArray(muted.data) ? muted.data : []
			} catch (error) {
				logger.error('Failed to load the blocked and muted accounts', { error })
				showError(t('social', 'Failed to load the blocked and muted accounts'))
			} finally {
				this.loading = false
			}
		},
		/** @param {import('../types/Mastodon.js').Account} account the account to unblock */
		async unblock(account) {
			// the store action keeps the relationship state the profile page reads
			await this.act(account, 'unblockAccount', 'blocked')
		},
		/** @param {import('../types/Mastodon.js').Account} account the account to unmute */
		async unmute(account) {
			await this.act(account, 'unmuteAccount', 'muted')
		},
		/**
		 * @param {import('../types/Mastodon.js').Account} account the account acted on
		 * @param {string} action the store action to dispatch
		 * @param {string} list the list to take the account off on success
		 */
		async act(account, action, list) {
			this.busy.push(account.id)
			try {
				const result = await this.$store.dispatch(action, { id: account.id })
				// the store reports its own failure; leave the row in place then,
				// so nothing claims an account was unblocked when it was not
				if (result) {
					this[list] = this[list].filter((entry) => entry.id !== account.id)
				}
			} finally {
				this.busy = this.busy.filter((id) => id !== account.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__blocked {
	max-width: 600px;
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}

	h3 {
		margin: 24px 0 8px;
		font-size: 16px;
		font-weight: bold;
	}

	section:first-of-type h3 {
		margin-top: 12px;
	}
}

.social__blocked-hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.blocked-account {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);

	&__user {
		display: flex;
		align-items: center;
		gap: 12px;
		min-width: 0;

		a {
			display: flex;
			flex-direction: column;
			min-width: 0;
		}
	}

	&__name {
		font-weight: bold;
	}

	&__acct {
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
	}
}

.loading-indicator {
	text-align: center;
	padding: 20px;
}
</style>

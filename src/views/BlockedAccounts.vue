<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__blocked">
		<h2>{{ t('social', 'Blocked and muted accounts') }}</h2>
		<p class="social__blocked-hint">
			{{ t('social', 'Blocking severs the relationship in both directions and hides the account everywhere. Muting only hides it from you, without the account knowing. A whole server can be hidden, and words can be filtered out wherever they appear.') }}
		</p>

		<section>
			<h3>{{ t('social', 'Blocked') }}</h3>
			<transition name="empty">
				<NcEmptyContent
					v-if="!loading && blocked.length === 0"
					:name="t('social', 'No blocked accounts')"
					:description="t('social', 'Accounts you block from their profile show up here.')">
					<template #icon>
						<Cancel />
					</template>
				</NcEmptyContent>
			</transition>
			<!-- keyed on the account id, never on the index: an index key makes
			     Vue patch the row above into the one below on removal and drop
			     the last row, so the wrong row would collapse -->
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="account in blocked" :key="`block-${account.id}`" class="blocked-account">
					<div class="blocked-account__user">
						<ActorAvatar :actor="account" />
						<router-link :to="{ name: 'profile', params: { account: account.acct } }">
							<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
							<span class="blocked-account__acct">{{ account.acct }}</span>
						</router-link>
					</div>
					<NcButton
						:disabled="busy.includes(account.id)"
						:aria-label="t('social', 'Unblock')"
						@click="unblock(account)">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('social', 'Unblock') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<section>
			<h3>{{ t('social', 'Muted') }}</h3>
			<transition name="empty">
				<NcEmptyContent
					v-if="!loading && muted.length === 0"
					:name="t('social', 'No muted accounts')"
					:description="t('social', 'Accounts you mute from their profile show up here.')">
					<template #icon>
						<VolumeOff />
					</template>
				</NcEmptyContent>
			</transition>
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="account in muted" :key="`mute-${account.id}`" class="blocked-account">
					<div class="blocked-account__user">
						<ActorAvatar :actor="account" />
						<router-link :to="{ name: 'profile', params: { account: account.acct } }">
							<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
							<span class="blocked-account__acct">{{ account.acct }}</span>
						</router-link>
					</div>
					<NcButton
						:disabled="busy.includes(account.id)"
						:aria-label="t('social', 'Unmute')"
						@click="unmute(account)">
						<template #icon>
							<VolumeHigh :size="20" />
						</template>
						{{ t('social', 'Unmute') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<section>
			<h3>{{ t('social', 'Hidden servers') }}</h3>
			<p class="social__blocked-hint">
				{{ t('social', 'Hiding a whole server hides every account on it and everything they post, and it takes your follows in both directions with it. It is the answer to being bothered by a server rather than by one person — until now the API had it and this page did not, so the only way was to block accounts one at a time.') }}
			</p>

			<form class="blocked-domain__add" @submit.prevent="hideDomain">
				<NcTextField
					v-model="domainDraft"
					class="blocked-domain__field"
					:label="t('social', 'The server to hide')"
					placeholder="example.social"
					:disabled="hidingDomain" />
				<NcButton type="submit" :disabled="hidingDomain || domainDraft.trim() === ''">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ t('social', 'Hide it') }}
				</NcButton>
			</form>

			<transition name="empty">
				<NcEmptyContent
					v-if="!loading && domains.length === 0"
					:name="t('social', 'No hidden servers')"
					:description="t('social', 'Nothing here is hidden from you by server.')">
					<template #icon>
						<Cancel />
					</template>
				</NcEmptyContent>
			</transition>

			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="domain in domains" :key="`domain-${domain}`" class="blocked-account">
					<div class="blocked-account__user">
						<span class="blocked-account__name">{{ domain }}</span>
					</div>
					<NcButton
						:disabled="busy.includes(domain)"
						:aria-label="t('social', 'Show again')"
						@click="showDomain(domain)">
						<template #icon>
							<VolumeHigh :size="20" />
						</template>
						{{ t('social', 'Show again') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<!-- last: the three lists above are people and servers, and this one
		     applies to everybody, the people you follow included -->
		<section id="filters">
			<h3>{{ t('social', 'Filtered words') }}</h3>
			<p class="social__blocked-hint">
				{{ t('social', 'Words you would rather not read, wherever they appear — from the people you follow as much as from anybody else. A post carrying one is folded away behind the name of the filter, or taken out of your timelines altogether. Filters are yours alone, nobody is told about them, and a filter set in a phone app has been applying here all along.') }}
			</p>
			<FiltersSettings />
		</section>

		<div v-if="loading" class="loading-indicator">
			{{ t('social', 'Loading…') }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import ActorAvatar from '../components/ActorAvatar.vue'
import FiltersSettings from '../components/FiltersSettings.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'

export default {
	name: 'BlockedAccounts',
	components: {
		ActorAvatar,
		FiltersSettings,
		NcButton,
		NcEmptyContent,
		NcTextField,
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
			/** @type {string[]} the servers hidden from this account */
			domains: [],
			domainDraft: '',
			hidingDomain: false,
			busy: [],
			loading: true,
		}
	},

	computed: {
		...mapStores(useAccountStore),
	},

	async mounted() {
		await this.fetchAll()
	},

	methods: {
		async fetchAll() {
			this.loading = true
			try {
				const [blocked, muted, domains] = await Promise.all([
					axios.get(generateUrl('apps/social/api/v1/blocks')),
					axios.get(generateUrl('apps/social/api/v1/mutes')),
					axios.get(generateUrl('apps/social/api/v1/domain_blocks')),
				])
				this.blocked = Array.isArray(blocked.data) ? blocked.data : []
				this.muted = Array.isArray(muted.data) ? muted.data : []
				this.domains = Array.isArray(domains.data) ? domains.data : []
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
		 * Hides a whole server from this account.
		 *
		 * @return {Promise<void>}
		 */
		async hideDomain() {
			const domain = this.domainDraft.trim().replace(/^@/, '').toLowerCase()
			if (domain === '') {
				return
			}

			this.hidingDomain = true
			try {
				await axios.post(generateUrl('apps/social/api/v1/domain_blocks'), { domain })
				this.domainDraft = ''
				// re-read rather than push: the server decides what the domain
				// normalises to, and a row that said something else would be a
				// row the unhide button could not act on
				await this.fetchAll()
			} catch (error) {
				logger.error('Failed to hide the server', { error })
				showError(t('social', 'Could not hide that server'))
			} finally {
				this.hidingDomain = false
			}
		},

		/**
		 * @param {string} domain the server to show again
		 * @return {Promise<void>}
		 */
		async showDomain(domain) {
			this.busy.push(domain)
			try {
				await axios.delete(generateUrl('apps/social/api/v1/domain_blocks'), {
					data: { domain },
				})
				this.domains = this.domains.filter((one) => one !== domain)
			} catch (error) {
				logger.error('Failed to show the server again', { error })
				showError(t('social', 'Could not show that server again'))
			} finally {
				this.busy = this.busy.filter((one) => one !== domain)
			}
		},

		/**
		 * @param {import('../types/Mastodon.js').Account} account the account acted on
		 * @param {string} action the name of the account-store action to call
		 * @param {string} list the list to take the account off on success
		 */
		async act(account, action, list) {
			this.busy.push(account.id)
			try {
				const result = await this.accountStore[action]({ id: account.id })
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
	max-width: var(--social-column);
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

/**
 * An unblocked or unmuted account collapses out of its list rather than
 * blinking away, so the row that answered stays visible for the moment it
 * takes to go and the rows below close the gap instead of jumping.
 *
 * Same motion as the shared `list` transition in App.vue — .2s ease for the
 * fade and the shift — with the height taken out over .3s ease-out, the way
 * the welcome banner in Timeline.vue collapses. The max-height is headroom
 * for one row, not a measurement: it only has to be larger than a row.
 */
.collapse-enter-active,
.collapse-leave-active {
	overflow: hidden;
	max-height: 120px;
	transition: opacity .2s ease, transform .2s ease, max-height .3s ease-out, padding .3s ease-out;
}

.collapse-enter-from,
.collapse-leave-to {
	opacity: 0;
	transform: translateY(-6px);
	max-height: 0;
	padding-top: 0;
	padding-bottom: 0;
}

/* an inserted row pushes the others down instead of displacing them */
.collapse-move {
	transition: transform .2s ease;
}

/* the empty state arrives as the last row finishes collapsing, not on top of it */
.empty-enter-active {
	transition: opacity .2s ease .25s, transform .2s ease .25s;
}

.empty-leave-active {
	transition: opacity .15s ease;
}

.empty-enter-from,
.empty-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
	.collapse-enter-active,
	.collapse-leave-active,
	.collapse-move,
	.empty-enter-active,
	.empty-leave-active {
		transition: none;
	}
}

.blocked-domain__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 8px;
}

.blocked-domain__field {
	max-width: 320px;
}

</style>

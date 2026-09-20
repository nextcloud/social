<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- nothing at all where there is nobody to ask for: this page is also
	     served to a reader with no session, and a "follow more people" hint
	     shown to somebody who cannot follow anybody is noise -->
	<section v-if="available" class="graph">
		<h3 class="graph__title">
			{{ t('social', 'Followed by people you follow') }}
		</h3>

		<!--
			The whole method needs a starting handful of follows, and saying so
			is the only honest empty state: "no suggestions" to somebody who
			follows nobody reads as a verdict on them rather than as a
			description of an empty graph.
		-->
		<p v-if="needs > 0" class="graph__hint">
			{{ n('social', 'Follow one more account and this can look at who they follow.',
				'Follow {count} more accounts and this can look at who they follow.', needs, { count: needs }) }}
		</p>

		<template v-else>
			<p class="graph__hint">
				{{ t('social', 'This asks the servers of the people you follow who they follow. Accounts several of them follow come first.') }}
			</p>

			<NcButton v-if="!asked" :disabled="loading" @click="load">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<AccountMultiplePlus v-else :size="20" />
				</template>
				{{ t('social', 'Look at who they follow') }}
			</NcButton>

			<NcLoadingIcon v-else-if="loading" class="graph__loading" :size="32" />

			<ul v-else-if="suggestions.length" class="graph__list">
				<li v-for="suggestion in suggestions" :key="suggestion.account.acct" class="graph__row">
					<router-link
						class="graph__person"
						:to="{ name: 'profile', params: { account: suggestion.account.acct } }">
						<ActorAvatar
							class="graph__avatar"
							:actor="suggestion.account"
							:size="40"
							:link="false" />
						<span class="graph__names">
							<span class="graph__name">
								{{ suggestion.account.display_name || suggestion.account.username }}
							</span>
							<span class="graph__handle">@{{ suggestion.account.acct }}</span>
							<!-- the count is the claim and the handles are the
							     evidence for it; a suggestion a reader cannot
							     check is one they can only take on trust -->
							<span class="graph__why">{{ why(suggestion) }}</span>
						</span>
					</router-link>
					<!-- the same follow-by-handle the search beside it uses:
					     `FollowButton` waits for a relationship the account
					     store has never fetched for a stranger, so it renders
					     nothing at all in a list like this one -->
					<NcButton
						class="graph__follow"
						:variant="isFollowed(suggestion.account) ? 'success' : 'primary'"
						:disabled="isPending(suggestion.account) || isFollowed(suggestion.account)"
						@click="follow(suggestion.account)">
						<template v-if="isPending(suggestion.account)" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ isFollowed(suggestion.account) ? t('social', 'Following') : t('social', 'Follow') }}
					</NcButton>
				</li>
			</ul>

			<p v-else class="graph__hint">
				{{ t('social', 'Nobody new came out of it. Many servers do not publish who an account follows, so this works better the more people you follow.') }}
			</p>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'

/**
 * "Whom to follow", asked of the fediverse rather than of this server.
 *
 * Behind a button rather than loaded with the page: the answer costs one
 * request to every server the reader follows somebody on, and spending that
 * on somebody who came to look at hashtags is not this app's to spend.
 */
export default {
	name: 'FollowGraphSuggestions',

	components: {
		AccountMultiplePlus,
		ActorAvatar,
		NcButton,
		NcLoadingIcon,
	},

	setup() {
		return useFollowByHandle()
	},

	data() {
		return {
			/** false once the server says there is no viewer to ask about */
			available: true,
			/** how many more follows it would take to be worth asking */
			needs: 0,
			/** whether the walk has been made */
			asked: false,
			loading: false,
			suggestions: [],
		}
	},

	async mounted() {
		// only to learn whether there is a graph at all, which the server
		// answers without asking anybody anything
		await this.load(false)
	},

	methods: {
		t: translate,
		n: translatePlural,

		/**
		 * @param {boolean} walk whether to accept the cost of asking other
		 *                       servers; false asks the status route instead,
		 *                       which answers from this server alone
		 */
		async load(walk = true) {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = walk
					? await axios.get(generateUrl('apps/social/api/v1/follow_graph'), { params: { limit: 20 } })
					: await axios.get(generateUrl('apps/social/api/v1/follow_graph/status'))
				this.needs = data?.needs ?? 0
				if (walk) {
					this.suggestions = Array.isArray(data?.suggestions) ? data.suggestions : []
					this.asked = true
				}
			} catch (error) {
				const status = error?.response?.status
				if (status === 401 || status === 403) {
					// a public page, or a session that ended; either way there
					// is no "people you follow" to work from
					this.available = false
				} else {
					logger.error('could not read the follow graph', { error })
				}

				if (walk) {
					this.asked = true
					this.suggestions = []
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} suggestion one suggestion as the server sent it
		 * @return {string} who follows this account, of the people the reader follows
		 */
		why(suggestion) {
			const via = Array.isArray(suggestion.via) ? suggestion.via : []
			if (via.length === 0) {
				return this.n(
					'social',
					'Followed by %n person you follow',
					'Followed by %n people you follow',
					suggestion.followed_by,
				)
			}

			const named = via.map((handle) => '@' + handle).join(', ')
			if (suggestion.followed_by > via.length) {
				return this.n(
					'social',
					'Followed by {named} and %n other you follow',
					'Followed by {named} and %n others you follow',
					suggestion.followed_by - via.length,
					{ named },
				)
			}

			return translate('social', 'Followed by {named}', { named })
		},
	},
}
</script>

<style scoped lang="scss">
.graph {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-block-end: calc(var(--default-grid-baseline) * 4);
}

.graph__title {
	font-weight: bold;
}

.graph__hint {
	color: var(--color-text-maxcontrast);
}

.graph__list {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.graph__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.graph__person {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex: 1 1 260px;
	min-width: 0;
	padding: var(--default-grid-baseline);
	border-radius: var(--border-radius-element, var(--border-radius-large));

	&:hover,
	&:focus-visible {
		background-color: var(--color-background-hover);
	}
}

.graph__names {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.graph__name {
	font-weight: bold;
}

.graph__handle,
.graph__why {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.graph__loading {
	margin-block: calc(var(--default-grid-baseline) * 2);
}
</style>

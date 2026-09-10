<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- Show button only if user is authenticated and she is not the same as the account viewed -->
	<div v-if="!serverData.public && relationship !== undefined">
		<!--
		  One real button, not two swapped by a :hover rule. The pair used to be
		  a "Following" label with no handler plus an "Unfollow" button that
		  `display: none` kept out of the tab order until a pointer hovered the
		  container — so a keyboard user and every touch device had no way to
		  unfollow anyone at all. The label changes on hover and focus instead.
		-->
		<NcButton v-if="relationship.following"
			:disabled="loading"
			class="follow-button follow-button--following"
			:variant="unfollowIntent ? 'error' : 'success'"
			:aria-label="t('social', 'Unfollow {account}', { account: uid })"
			@mouseenter="unfollowIntent = true"
			@mouseleave="unfollowIntent = false"
			@focus="unfollowIntent = true"
			@blur="unfollowIntent = false"
			@click="askToUnfollow">
			<template #icon>
				<CloseOctagon v-if="unfollowIntent" :size="20" />
				<Check v-else :size="20" />
			</template>
			{{ unfollowIntent ? t('social', 'Unfollow') : t('social', 'Following') }}
		</NcButton>
		<NcButton v-else-if="relationship.requested"
			:disabled="true"
			variant="secondary"
			class="follow-button">
			{{ t('social', 'Requested') }}
		</NcButton>
		<NcButton v-else
			:disabled="loading"
			variant="primary"
			class="follow-button"
			@click="follow">
			{{ t('social', 'Follow') }}
		</NcButton>

		<!-- unfollowing is quiet and easy to do by accident, and on a locked
		     account following again means asking again -->
		<NcDialog v-model:open="confirmUnfollow"
			:name="t('social', 'Unfollow {account}?', { account: uid })"
			:buttons="unfollowButtons">
			<p class="unfollow-hint">
				{{ t('social', 'Their posts stop appearing in your home timeline. If their account is locked you will have to ask again to follow them.') }}
			</p>
		</NcDialog>
	</div>
</template>

<script>
import accountMixins from '../mixins/accountMixins.js'
import currentUser from '../mixins/currentUserMixin.js'
import Check from 'vue-material-design-icons/Check.vue'
import CloseOctagon from 'vue-material-design-icons/CloseOctagon.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate } from '@nextcloud/l10n'
import logger from '../services/logger.js'

export default {
	name: 'FollowButton',
	components: {
		Check,
		CloseOctagon,
		NcButton,
		NcDialog,
	},
	mixins: [
		accountMixins,
		currentUser,
	],
	props: {
		uid: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: false,
			/** whether the pointer or the keyboard is on the button */
			unfollowIntent: false,
			confirmUnfollow: false,
		}
	},
	computed: {
		/** @return {boolean} */
		isCurrentUserFollowing() {
			return this.$store.getters.isFollowingUser(this.profileAccount)
		},
		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.$store.getters.currentAccount
		},
		unfollowButtons() {
			return [
				{
					label: translate('social', 'Cancel'),
					callback: () => {
						this.confirmUnfollow = false
					},
				},
				{
					label: translate('social', 'Unfollow'),
					variant: 'error',
					callback: () => this.unfollow(),
				},
			]
		},
	},
	methods: {
		t: translate,
		askToUnfollow() {
			this.confirmUnfollow = true
		},
		async follow() {
			logger.debug('Following an account', { account: this.profileAccount })
			try {
				this.loading = true
				await this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.profileAccount })
			} catch (error) {
				// the store says what went wrong; without this the rejection
				// had nowhere to go but the console, as an unhandled one
				logger.error('Failed to follow an account', { error })
			} finally {
				this.loading = false
			}
		},
		async unfollow() {
			this.confirmUnfollow = false
			logger.debug('Unfollowing an account', { account: this.profileAccount })
			try {
				this.loading = true
				await this.$store.dispatch('unfollowAccount', { currentAccount: this.cloudId, accountToUnfollow: this.profileAccount })
			} catch (error) {
				logger.error('Failed to unfollow an account', { error })
			} finally {
				this.loading = false
				this.unfollowIntent = false
			}
		},
	},
}
</script>
<style scoped lang="scss">
	.follow-button {
		width: 150px !important;
		border-radius: 8px !important;
		font-weight: 600 !important;
	}

	.unfollow-hint {
		padding: 0 12px 12px;
		color: var(--color-text-lighter);
		line-height: 1.5;
	}

	.user-entry {
		padding: 20px;
		margin-bottom: 10px;
	}

	.user-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		flex-shrink: 0;
	}

	.post-author {
		font-weight: bold;
	}

	.entry-content {
		display: flex;
		align-items: flex-start;
	}

	.user-details {
		flex-grow: 1;
	}

	.user-description {
		color: var(--color-text-lighter);
	}

	button {
		min-width: 110px;
	}

	button * {
		cursor: pointer;
	}
</style>

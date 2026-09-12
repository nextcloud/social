<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="item" class="user-entry">
		<div class="entry-content">
			<div class="user-avatar">
				<NcAvatar v-if="isLocal"
					:size="32"
					:user="item.username"
					:disable-tooltip="true" />
				<NcAvatar v-else :url="item.avatar" />
			</div>
			<div class="user-details">
				<router-link v-if="!serverData.public" :to="{ name: 'profile', params: { account: item.acct }}">
					<span class="post-author">
						<DisplayName :text="item.display_name" :emojis="item.emojis" />
					</span>
					<span class="user-description">
						{{ item.acct }}
					</span>
				</router-link>
				<a v-else
					:href="item.id"
					target="_blank"
					rel="noreferrer">
					<span class="post-author">
						{{ item.display_name }}
					</span>
					<span class="user-description">
						{{ item.acct }}
					</span>
				</a>
				<!-- Sanitized: the bio is remote HTML, see sanitizeHtml.js -->
				<!-- eslint-disable-next-line vue/no-v-html -->
				<p v-html="sanitizedNote" />
			</div>
			<FollowButton v-if="displayFollowButton" :uid="item.acct" />
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import DisplayName from './DisplayName.js'
import FollowButton from './FollowButton.vue'
import { sanitizeHtml } from '../utils/sanitizeHtml.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'UserEntry',
	components: {
		DisplayName,
		FollowButton,
		NcAvatar,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */
		item: {
			type: Object,
			default: () => {},
		},
		displayFollowButton: {
			type: Boolean,
			default: true,
		},
	},
	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
		...mapStores(useAccountStore),
		/**
		 * The account's bio, reduced to markup that is safe to inject.
		 *
		 * @return {string}
		 */
		sanitizedNote() {
			return sanitizeHtml(this.item.note ?? '')
		},
		/**
		 * Where this entry stands with the reader.
		 *
		 * This component only mixes in currentUserMixin, so the `relationship`
		 * the mount guard tested was always undefined and the guard could
		 * never hold: twenty followers meant twenty requests, on every mount.
		 *
		 * @return {import('../types/Mastodon.js').Relationship|undefined}
		 */
		relationship() {
			return this.accountStore.getRelationshipWith(this.item?.id)
		},
		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.item.acct.includes('@')
		},
	},
	mounted() {
		if (!this.serverData.public && this.relationship === undefined) {
			// batched: the action collects everybody who asks in the same
			// moment and sends the ids as one request
			this.accountStore.fetchRelationship(this.item.id)
		}
	},
}
</script>
<style scoped lang="scss">
.user-entry {
	width: 100%;
	padding: 16px 20px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	box-sizing: border-box;
}

.entry-content {
	display: flex;
	align-items: flex-start;
	gap: 12px;

	.user-avatar {
		flex-shrink: 0;
		margin-top: 2px;
	}

	.user-details {
		flex: 1;
		min-width: 0;

		a {
			display: inline-flex;
			align-items: baseline;
			gap: 6px;
			text-decoration: none;
			color: var(--color-main-text);

			&:hover .post-author {
				color: var(--color-primary-element);
			}
		}

		.post-author {
			font-weight: 650;
			font-size: 14px;
		}

		.user-description {
			font-size: 13px;
			color: var(--color-text-lighter);
		}

		p {
			margin: 4px 0 0;
			font-size: 13px;
			line-height: 1.5;
			color: var(--color-text-lighter);
			overflow-wrap: break-word;
			overflow: hidden;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			max-height: 3em;
		}
	}
}
</style>

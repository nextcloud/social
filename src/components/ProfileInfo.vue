<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="profileAccount && accountInfo" class="user-profile">
		<NcAvatar v-if="isLocal"
			:user="localUid"
			:disable-tooltip="true"
			:size="128" />
		<NcAvatar v-else
			:url="accountInfo.avatar"
			:disable-tooltip="true"
			:size="128" />
		<h2>{{ displayName }}</h2>
		<ul class="user-profile__info user-profile__sections">
			<li>
				<router-link :to="{ name: 'profile', params: { account: uid } }" class="icon-category-monitoring">
					{{ accountInfo.statuses_count }} {{ t('social', 'posts') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.following', params: { account: uid } }" class="icon-category-social">
					{{ accountInfo.following_count }}  {{ t('social', 'following') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.followers', params: { account: uid } }" class="icon-category-social">
					{{ accountInfo.followers_count }}  {{ t('social', 'followers') }}
				</router-link>
			</li>
		</ul>
			<div class="user-profile__actions">
				<FollowButton :uid="uid" />
				<NcButton v-if="serverData.public"
					type="primary"
					@click="followRemote">
					{{ t('social', 'Follow') }}
				</NcButton>
			</div>
		</div>

		<!-- Hack to render note safely -->
		<MessageContent v-if="accountInfo.note" class="user-profile__note user-profile__info" :item="{content: accountInfo.note, tag: [], mentions: []}" />
	</div>
</template>

<script>
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import currentUser from '../mixins/currentUserMixin.js'
import FollowButton from './FollowButton.vue'

export default {
	name: 'ProfileInfo',
	components: {
		FollowButton,
		NcAvatar,
		NcButton,
		OpenInNew,
	},
	mixins: [
		accountMixins,
		currentUser,
		serverData,
	],
	props: {
		uid: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
		/** @return {string} */
		localUid() {
			// Returns only the local part of a username
			return (this.uid.indexOf('@') === -1) ? this.uid : this.uid.slice(0, this.uid.indexOf('@'))
		},
		/** @return {string} */
		displayName() {
			return this.accountInfo.display_name ?? this.accountInfo.username ?? this.profileAccount
		},
		/** @return {string} */
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		},
		/** @return {import('../types/Mastodon.js').Field} */
		website() {
			return this.accountInfo.fields.find(field => field.name === 'Website')
		},
	},
	methods: {
		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		},

		t: translate,
	},
}

</script>
<style scoped lang="scss">
	.user-profile {
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		max-width: 800px;
		margin: 0 auto 30px;
		padding: 24px;
		text-align: center;
		background-color: var(--color-main-background);
		
		h2 {
			margin-top: 16px;
			font-size: 24px;
			font-weight: bold;
		}

		&__info {
			margin-bottom: 12px;
			display: flex;
			gap: 8px;
			justify-content: center;
			color: var(--color-text-maxcontrast);

			a {
				display: flex;
				align-items: center;
				gap: 4px;
				color: var(--color-text-maxcontrast);
				opacity: 0.8;

				&:hover {
					opacity: 1;
					text-decoration: none;
				}
			}
		}

		&__actions {
			display: flex;
			gap: 10px;
			margin-top: 10px;
		}

		&__note {
			text-align: start;
		}

		&__sections {
			display: flex;

			li {
				flex-grow: 1;

				a {
					padding: 10px;
					padding-left: 24px;
					display: inline-block;
					background-position: 0 center;
					height: 40px;
					opacity: .6;

					&.router-link-exact-active,
					&:focus {
						opacity: 1;
						border-bottom: 1px solid var(--color-main-text);
					}

					&.disabled {
						opacity: 1;
						border-bottom: none;
						text-decoration: none;
						cursor: auto;
						pointer-events: none;
					}
				}
			}
		}
	}
</style>

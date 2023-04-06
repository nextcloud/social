<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<div v-if="profileAccount && accountInfo" class="user-profile">
		<NcAvatar v-if="isLocal"
			:user="localUid"
			:disable-tooltip="true"
			:size="128" />
		<NcAvatar v-else
			:url="avatarUrl"
			:disable-tooltip="true"
			:size="128" />
		<h2>{{ displayName }}</h2>
		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<ul v-if="isLocal" class="user-profile__info user-profile__sections">
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
		<p class="user-profile__info">
			<a :href="accountInfo.url" target="_blank">@{{ accountInfo.acct }}<OpenInNew :size="15" /></a>
		</p>

		<p v-if="website" class="user-profile__info">
			{{ t('social', 'Website') }}: <a :href="website.value">{{ website.value }}<OpenInNew :size="15" /></a>
		</p>

		<!-- Hack to render note safely -->
		<MessageContent v-if="accountInfo.note" class="user-profile__note" :item="{content: accountInfo.note, tag: [], mentions: []}" />

		<FollowButton class="user-profile__info" :account="accountInfo.acct" :uid="uid" />
		<NcButton v-if="serverData.public"
			class="user-profile__info primary"
			@click="followRemote">
			{{ t('social', 'Follow') }}
		</NcButton>
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
		flex-wrap: wrap;
		flex-direction: column;
		justify-content: space-between;
		width: 100%;
		text-align: center;
		padding-top: 20px;
		align-items: center;
		margin-bottom: 20px;

		&__info {
			margin-bottom: 12px;
			display: flex;
			gap: 4px;

			a {
				display: flex;
				gap: 4px;

				&:hover {
					text-decoration: underline;
				}
			}

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
				}
			}
		}
	}
</style>

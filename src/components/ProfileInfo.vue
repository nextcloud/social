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
		<NcAvatar v-if="accountInfo.local"
			:user="localUid"
			:disable-tooltip="true"
			:size="128" />
		<NcAvatar v-else
			:url="avatarUrl"
			:disable-tooltip="true"
			:size="128" />
		<h2>{{ displayName }}</h2>
		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<ul v-if="accountInfo.details && accountInfo.local" class="user-profile__info user-profile__sections">
			<li>
				<router-link :to="{ name: 'profile', params: { account: uid } }" class="icon-category-monitoring">
					{{ getCount('post') }} {{ t('social', 'posts') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.following', params: { account: uid } }" class="icon-category-social">
					{{ getCount('following') }}  {{ t('social', 'following') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.followers', params: { account: uid } }" class="icon-category-social">
					{{ getCount('followers') }}  {{ t('social', 'followers') }}
				</router-link>
			</li>
		</ul>
		<p class="user-profile__info">
			<a :href="accountInfo.url" target="_blank">@{{ accountInfo.account }}</a>
		</p>

		<p v-if="accountInfo.website" class="user-profile__info">
			{{ t('social', 'Website') }}: <a :href="accountInfo.website.value">{{ accountInfo.website.value }}</a>
		</p>

		<FollowButton class="user-profile__info" :account="accountInfo.account" :uid="uid" />
		<NcButton v-if="serverData.public"
			class="user-profile__info primary"
			@click="followRemote">
			{{ t('social', 'Follow') }}
		</NcButton>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import currentUser from '../mixins/currentUserMixin.js'
import follow from '../mixins/follow.js'
import FollowButton from './FollowButton.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProfileInfo',
	components: {
		FollowButton,
		NcAvatar,
		NcButton,
	},
	mixins: [
		accountMixins,
		currentUser,
		serverData,
		follow,
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
		localUid() {
			// Returns only the local part of a username
			return (this.uid.indexOf('@') === -1) ? this.uid : this.uid.slice(0, this.uid.indexOf('@'))
		},
		displayName() {
			if (typeof this.accountInfo.name !== 'undefined' && this.accountInfo.name !== '') {
				return this.accountInfo.name
			}
			if (typeof this.accountInfo.preferredUsername !== 'undefined' && this.accountInfo.preferredUsername !== '') {
				return this.accountInfo.preferredUsername
			}
			return this.profileAccount
		},
		getCount() {
			const account = this.accountInfo
			return (field) => account.details.count ? account.details.count[field] : ''
		},
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		},
	},
	methods: {
		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		},
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

			a:hover {
				text-decoration: underline;
			}
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

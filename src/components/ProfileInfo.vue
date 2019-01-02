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
	<div v-if="account && accountInfo" class="user-profile">
		<div class="user-profile--info">
			<avatar v-if="accountInfo.local" :user="uid" :disable-tooltip="true"
				:size="128"
			/>
			<avatar v-else :url="avatarUrl" :disable-tooltip="true"
				:size="128"
			/>
			<h2>{{ displayName }}</h2>
			<p>{{ accountInfo.account }}</p>
			<p v-if="accountInfo.website">
				Website: <a :href="accountInfo.website.value">
					{{ accountInfo.website.value }}
				</a>
			</p>
			<follow-button :account="accountInfo.account" />
		</div>
		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<ul v-if="accountInfo.details && accountInfo.local" class="user-profile--sections">
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
	</div>
</template>
<style scoped>
	.user-profile {
		display: flex;
		width: 100%;
		text-align: center;
		padding-top: 20px;
		align-items: flex-end;
		margin-bottom: 20px;
	}
	h2 {
		margin-bottom: 5px;
	}

	.user-profile--info {
		width: 40%;
	}
	.user-profile--sections {
		width: 60%;
		display: flex;
		margin-bottom: 30px;
	}
	.user-profile--sections li {
		flex-grow: 1;
	}
	.user-profile--sections li a {
		padding: 10px;
		padding-left: 24px;
		display: inline-block;
		background-position: 0 center;
		height: 40px;
		opacity: .6;
	}
	.user-profile--sections li a.router-link-exact-active,
	.user-profile--sections li a:focus{
		opacity: 1;
		border-bottom: 1px solid var(--color-main-text);
	}
</style>
<script>

import { Avatar } from 'nextcloud-vue'
import serverData from '../mixins/serverData'
import currentUser from '../mixins/currentUserMixin'
import follow from '../mixins/follow'
import FollowButton from './FollowButton'

export default {
	name: 'ProfileInfo',
	components: {
		FollowButton,
		Avatar
	},
	mixins: [
		serverData,
		currentUser,
		follow
	],
	props: {
		uid: {
			type: String,
			default: ''
		}
	},
	data: function() {
		return {
			followingText: t('social', 'Following')
		}
	},
	computed: {
		account() {
			return (this.uid.indexOf('@') === -1) ? this.uid + '@' + this.hostname : this.uid
		},
		displayName() {
			if (typeof this.accountInfo.name !== 'undefined' && this.accountInfo.name !== '') {
				return this.accountInfo.name
			}
			return this.account
		},
		accountInfo: function() {
			return this.$store.getters.getAccount(this.account)
		},
		getCount() {
			return (field) => this.accountInfo.details.count ? this.accountInfo.details.count[field] : ''
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		}
	},
	methods: {

	}
}

</script>

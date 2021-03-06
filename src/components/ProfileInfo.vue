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
		<avatar v-if="accountInfo.local" :user="localUid" :disable-tooltip="true"
			:size="128" />
		<avatar v-else :url="avatarUrl" :disable-tooltip="true"
			:size="128" />
		<h2>{{ displayName }}</h2>
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
		<p>@{{ accountInfo.account }}</p>
		<p v-if="accountInfo.website">
			Website: <a :href="accountInfo.website.value">
				{{ accountInfo.website.value }}
			</a>
		</p>
		<follow-button :account="accountInfo.account" :uid="uid" />
		<button v-if="serverData.public" class="primary" @click="followRemote">
			{{ t('social', 'Follow') }}
		</button>
	</div>
</template>
<style scoped>
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
	}
	h2 {
		margin-bottom: 5px;
	}

	.user-profile--sections {
		display: flex;
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
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import accountMixins from '../mixins/accountMixins'
import serverData from '../mixins/serverData'
import currentUser from '../mixins/currentUserMixin'
import follow from '../mixins/follow'
import FollowButton from './FollowButton.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProfileInfo',
	components: {
		FollowButton,
		Avatar
	},
	mixins: [
		accountMixins,
		currentUser,
		serverData,
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
		localUid() {
			// Returns only the local part of a username
			return (this.uid.indexOf('@') === -1) ? this.uid : this.uid.substr(0, this.uid.indexOf('@'))
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
			let account = this.accountInfo
			return (field) => account.details.count ? account.details.count[field] : ''
		},
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		}
	},
	methods: {
		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		}
	}
}

</script>

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
	<div class="user-profile" v-if="uid && accountInfo">
		<div class="user-profile--info">
			<avatar :user="profileInfo.uid" :displayName="displayName" :size="128" />
			<h2 v-if="accountInfo.displayname">{{accountInfo.displayname.value}}</h2>
			<p>{{accountInfo.cloudId}}</p>
			<p v-if="accountInfo.website">Website: <a :href="accountInfo.website.value">{{accountInfo.website.value}}</a></p>

			<button v-if="!serverData.public" class="primary" @click="follow">Follow this user</button>
		</div>


		<ul class="user-profile--sections">
			<li id="social-timeline" class="">
				<a href="#" class="icon-category-monitoring">{{ accountInfo.posts }} posts</a>
			</li>
			<li id="social-timeline" class="">
				<a href="#" class="icon-category-social">{{ accountInfo.following }} following</a>
			</li>
			<li id="social-timeline" class="">
				<a href="#" class="icon-category-social">{{ accountInfo.followers }} followers</a>
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
		padding-left: 24px;
		background-position: 0px center;
		height: 40px;
	}
</style>
<script>

	import { Avatar } from 'nextcloud-vue'

	export default {
		name: 'ProfileInfo',
		props: ['uid'],
		components: {
			Avatar
		},
		methods: {
			follow() {
				console.log('TODO: implement following users');
			}
		},
		computed: {
			displayName() {
				console.log(this.accountInfo);
				return this.accountInfo.displayname.value || '';
			},
			serverData: function() {
				return this.$store.getters.getServerData;
			},
			accountInfo: function() {
				return this.$store.getters.getAccount(this.uid);
			},
			profileInfo() {
				return {
					uid: this.uid,
					server: OC.getHost()
				}
			}
		}
	}

</script>

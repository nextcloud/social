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
	<div class="social__wrapper">
		<profile-info :uid="uid" />
		<div class="social__container">
			<router-view name="details" />
		</div>
	</div>
</template>

<style scoped>
	.social__wrapper {
		max-width: 700px;
		margin: 15px auto;
	}

	.social__welcome {
		max-width: 700px;
		margin: 15px auto;
		padding: 15px;
		border-radius: 10px;
		background-color: var(--color-background-dark);
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.social__welcome .icon-close {
		float:right;
	}

	.social__welcome .social-id {
		font-weight: bold;
	}

	.social__timeline {
		max-width: 700px;
		margin: 15px auto;
	}

	.new-post {
		display: flex;
		padding: 10px;
		background-color: var(--color-main-background);
		position: sticky;
		top: 47px;
		z-index: 100;
		margin-bottom: 10px;
	}
	.new-post-author {
		padding: 5px;
	}
	.author .social-id {
		opacity: .5;
	}
	.new-post-form {
		flex-grow: 1;
		position: relative;
	}
	.message {
		width: 100%;
	}
	[contenteditable=true]:empty:before{
		content: attr(placeholder);
		display: block; /* For Firefox */
		opacity: .5;
	}
	input[type=submit] {
		width: 44px;
		height: 44px;
		margin: 0;
		padding: 13px;
		background-color: transparent;
		border: none;
		opacity: 0.3;
		position: absolute;
		bottom: 0;
		right: 0;
	}

	#app-content {
		position: relative;
	}

</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect,
	Avatar
} from 'nextcloud-vue'
import TimelineEntry from './../components/TimelineEntry'
import ProfileInfo from './../components/ProfileInfo'

export default {
	name: 'Profile',
	components: {
		PopoverMenu,
		AppNavigation,
		TimelineEntry,
		Multiselect,
		Avatar,
		ProfileInfo
	},
	data: function() {
		return {
			state: [],
			uid: null
		}
	},
	computed: {
		serverData: function() {
			return this.$store.getters.getServerData
		},
		currentUser: function() {
			return OC.getCurrentUser()
		},
		socialId: function() {
			return '@' + OC.getCurrentUser().uid + '@' + OC.getHost()
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		}
	},
	beforeMount() {
		this.uid = this.$route.params.account
		this.$store.dispatch('fetchAccountInfo', this.uid)
	},
	methods: {
	}
}
</script>

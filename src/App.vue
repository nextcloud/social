<template>
	<div v-if="!serverData.setup" class="app-social">
		<div v-if="!serverData.public" id="app-navigation">
			<app-navigation :menu="menu" />
		</div>
		<div id="app-content">
			<router-view :key="$route.fullPath" />
		</div>
	</div>
	<div v-else class="setup">
		<template v-if="serverData.isAdmin">
			<h2>{{ t('social', 'Social app setup') }}</h2>
			<p>{{ t('social', 'ActivityPub requires a fixed URL to make entries unique. Please configure a URL base. Note that this cannot be changed later without resetting the social app data.') }}</p>
			<form @submit.prevent="setCloudAddress">
				<p>
					<label class="hidden">{{ t('social', 'ActivityPub URL base') }}</label>
					<input :placeholder="serverData.cliUrl" v-model="cloudAddress" type="url"
						required>
					<input :value="t('social', 'Finish setup')" type="submit" class="primary">
				</p>
			</form>
		</template>
		<template v-else>
			<p>{{ t('social', 'The social app requires to be setup by the server administrator.') }}</p>
		</template>
	</div>
</template>

<style scoped>
	.app-social {
		width: 100%;
	}
	.setup {
		margin:	auto;
		width: 700px;
	}

	.setup input[type=url] {
		width: 300px;
	}
</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect,
	Avatar
} from 'nextcloud-vue'
import axios from 'nextcloud-axios'
import TimelineEntry from './components/TimelineEntry'
import ProfileInfo from './components/ProfileInfo'

export default {
	name: 'App',
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
			infoHidden: false,
			state: [],
			cloudAddress: ''
		}
	},
	computed: {
		url: function() {
			return OC.linkTo('social', 'img/nextcloud.png')
		},
		currentUser: function() {
			return OC.getCurrentUser()
		},
		socialId: function() {
			return '@' + OC.getCurrentUser().uid + '@' + OC.getHost()
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		serverData: function() {
			return this.$store.getters.getServerData
		},
		menu: function() {
			let defaultCategories = [
				{
					id: 'social-timeline',
					classes: [],
					icon: 'icon-category-monitoring',
					text: t('social', 'Timeline'),
					router: {
						name: 'timeline'
					}
				},
				{
					id: 'social-account',
					classes: [],
					icon: 'icon-user',
					text: t('social', 'Profile'),
					router: {
						name: 'profile',
						params: { account: this.currentUser.uid }
					}
				},
				{
					id: 'social-favorites',
					classes: [],
					href: '#',
					icon: 'icon-favorite',
					text: t('social', 'Favorites')
				},
				{
					id: 'social-direct-messages',
					classes: [],
					href: '#',
					icon: 'icon-comment',
					text: t('social', 'Direct messages')
				}
			]
			return {
				items: defaultCategories,
				loading: false
			}
		}
	},
	beforeMount: function() {
		// importing server data into the store
		const serverDataElmt = document.getElementById('serverData')
		if (serverDataElmt !== null) {
			this.$store.commit('setServerData', JSON.parse(document.getElementById('serverData').dataset.server))
		}
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		},
		setCloudAddress() {
			axios.post(OC.generateUrl('apps/social/api/v1/config/cloudAddress'), { cloudAddress: this.cloudAddress }).then((response) => {
				this.$store.commit('setServerDataEntry', 'setup', false)
				this.$store.commit('setServerDataEntry', 'cloudAddress', this.cloudAddress)
			})
		}
	}
}
</script>

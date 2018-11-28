<template>
	<div v-if="!serverData.setup" class="app-social">
		<div v-if="!serverData.public" id="app-navigation">
			<app-navigation :menu="menu" />
		</div>
		<div id="app-content">
			<Search v-if="searchTerm != ''" :term="searchTerm" />
			<router-view v-if="searchTerm === ''" :key="$route.fullPath" />
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

	#app-content .social__wrapper {
		margin: 15px calc(50% - 350px - 75px);
	}

	#social-spacer a:hover,
	#social-spacer a:focus {
		border: none !important;
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
import Search from './components/Search'

export default {
	name: 'App',
	components: {
		PopoverMenu,
		AppNavigation,
		TimelineEntry,
		Multiselect,
		Avatar,
		ProfileInfo,
		Search
	},
	data: function() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
			searchTerm: ''
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
					icon: 'icon-home',
					text: t('social', 'Home'),
					router: {
						name: 'timeline'
					}
				},
				{
					id: 'social-direct-messages',
					classes: [],
					router: {
						name: 'timeline',
						params: { type: 'direct' }
					},
					icon: 'icon-comment',
					text: t('social', 'Direct messages')
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
					id: 'social-spacer'
				},
				{
					id: 'social-local',
					classes: [],
					icon: 'icon-category-monitoring',
					text: t('social', 'Local timeline'),
					router: {
						name: 'timeline',
						params: { type: 'timeline' }
					}
				},
				{
					id: 'social-global',
					classes: [],
					icon: 'icon-link',
					text: t('social', 'Global timeline'),
					router: {
						name: 'timeline',
						params: { type: 'federated' }
					}
				}
			]
			return {
				items: defaultCategories,
				loading: false
			}
		}
	},
	watch: {
		$route(to, from) {
			this.searchTerm = ''
		}
	},
	beforeMount: function() {
		// importing server data into the store
		const serverDataElmt = document.getElementById('serverData')
		if (serverDataElmt !== null) {
			this.$store.commit('setServerData', JSON.parse(document.getElementById('serverData').dataset.server))
		}

		this.search = new OCA.Search(this.search, this.resetSearch)
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
		},
		search(term) {
			this.searchTerm = term
		},
		resetSearch() {
			this.searchTerm = ''
		}
	}
}
</script>

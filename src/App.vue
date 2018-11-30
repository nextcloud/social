<template>
	<div v-if="!serverData.setup" class="app-social">
		<div v-if="!serverData.public" id="app-navigation">
			<app-navigation :menu="menu" />
		</div>
		<div id="app-content">
			<div class="social__wrapper">
				<Search v-if="searchTerm != ''" :term="searchTerm" />
				<router-view v-if="searchTerm === ''" :key="$route.fullPath" />
			</div>
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
		<template v-else-if="serverData.error">
			<h2>{{ t('social', 'Social app setup') }}</h2>
			<p>{{ t('social', '.well-known/webfinger isn\'t properly set up!') }}</p>
			<p>{{ t('social', 'Social needs the .well-known auto discovery to be properly set up. If Nextcloud is not installed in the root of the domain it is often the case, that Nextcloud can\'t configure this automatically. To use Social the admin of this Nextcloud instance needs to manually configure the .well-known redirects: ') }}<a class="external_link" href="https://docs.nextcloud.com/server/15/go.php?to=admin-setup-well-known-URL" target="_blank" rel="noreferrer noopener">{{ t('social', 'Open Documentation') }} ↗</a></p>
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
		padding: 15px;
	}
	@media (min-width: 1200px) {
		#app-content .social__wrapper {
			margin: 15px calc(50% - 350px - 75px);
			max-width: 700px;
		}
	}

	#social-spacer a:hover,
	#social-spacer a:focus {
		border: none !important;
	}
	a.external_link {
		text-decoration: underline;
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
import currentuserMixin from './mixins/currentUserMixin'

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
	mixins: [currentuserMixin],
	data: function() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
			searchTerm: ''
		}
	},
	computed: {
		timeline: function() {
			return this.$store.getters.getTimeline
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

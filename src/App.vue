<template>
	<div v-if="!serverData.setup" id="app-social" :class="{public: serverData.public}">
		<AppNavigation v-if="!serverData.public" id="app-navigation">
			<AppNavigationItem v-for="item in menu.items" :key="item.key" :to="item.to"
				:title="item.title" :icon="item.icon" :exact="true" />
		</AppNavigation>
		<div id="app-content">
			<div v-if="serverData.isAdmin && !serverData.checks.success" class="setup social__wrapper">
				<h3 v-if="!serverData.checks.checks.wellknown">
					{{ t('social', '.well-known/webfinger isn\'t properly set up!') }}
				</h3>
				<p v-if="!serverData.checks.checks.wellknown">
					{{ t('social', 'Social needs the .well-known automatic discovery to be properly set up. If Nextcloud is not installed in the root of the domain, it is often the case that Nextcloud can\'t configure this automatically. To use Social, the admin of this Nextcloud instance needs to manually configure the .well-known redirects: ') }}<a class="external_link"
						href="https://docs.nextcloud.com/server/15/go.php?to=admin-setup-well-known-URL"
						target="_blank"
						rel="noreferrer noopener">
						{{ t('social', 'Open documentation') }} ↗
					</a>
				</p>
			</div>
			<Search v-if="searchTerm !== ''" :term="searchTerm" />
			<router-view v-if="searchTerm === ''" :key="$route.fullPath" />
		</div>
	</div>
	<div v-else class="setup">
		<template v-if="serverData.isAdmin">
			<h2>{{ t('social', 'Social app setup') }}</h2>
			<p>{{ t('social', 'ActivityPub requires a fixed URL to make entries unique. Note that this can not be changed later without resetting the Social app.') }}</p>
			<form @submit.prevent="setCloudAddress">
				<p>
					<label class="hidden">
						{{ t('social', 'ActivityPub URL base') }}
					</label>
					<input v-model="cloudAddress"
						:placeholder="serverData.cliUrl"
						type="url"
						required>
					<input :value="t('social', 'Finish setup')" type="submit" class="primary">
				</p>
				<template v-if="!serverData.checks.success">
					<h3 v-if="!serverData.checks.checks.wellknown">
						{{ t('social', '.well-known/webfinger isn\'t properly set up!') }}
					</h3>
					<p v-if="!serverData.checks.checks.wellknown">
						{{ t('social', 'Social needs the .well-known automatic discovery to be properly set up. If Nextcloud is not installed in the root of the domain, it is often the case that Nextcloud can\'t configure this automatically. To use Social, the admin of this Nextcloud instance needs to manually configure the .well-known redirects: ') }}<a class="external_link"
							href="https://docs.nextcloud.com/server/15/go.php?to=admin-setup-well-known-URL"
							target="_blank"
							rel="noreferrer noopener">
							{{ t('social', 'Open documentation') }} ↗
						</a>
					</p>
				</template>
			</form>
		</template>
		<template v-else>
			<p>{{ t('social', 'The Social app needs to be set up by the server administrator.') }}</p>
		</template>
	</div>
</template>

<style scoped>
	#app-social {
		width: 100%;
	}

	#app-content .social__wrapper {
		padding: 15px;
		max-width: 600px;
		margin: auto;
	}

	@media (min-width: 1200px) {
		#app-social:not(.public) #app-content .social__wrapper {
			margin: 15px calc(50% - 350px - 75px);
			max-width: 600px;
		}
	}

	.setup {
		margin: auto;
		width: 700px;
	}

	.setup input[type=url] {
		width: 300px;
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
import { AppNavigation, AppNavigationItem } from '@nextcloud/vue'

import axios from '@nextcloud/axios'
import Search from './components/Search.vue'
import currentuserMixin from './mixins/currentUserMixin'

export default {
	name: 'App',
	components: {
		AppNavigation,
		AppNavigationItem,
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
			const defaultCategories = [
				{
					id: 'social-timeline',
					icon: 'icon-home',
					title: t('social', 'Home'),
					to: {
						name: 'timeline'
					}
				},
				{
					id: 'social-direct-messages',
					icon: 'icon-comment',
					title: t('social', 'Direct messages'),
					to: {
						name: 'timeline',
						params: { type: 'direct' }
					}
				},
				{
					id: 'social-notifications',
					icon: 'icon-notifications',
					title: t('social', 'Notifications'),
					to: {
						name: 'timeline',
						params: { type: 'notifications' }
					}
				},
				{
					id: 'social-account',
					icon: 'icon-user',
					title: t('social', 'Profile'),
					to: {
						name: 'profile',
						params: { account: this.currentUser.uid }
					}
				},
				{
					id: 'social-liked',
					icon: 'icon-favorite',
					title: t('social', 'Liked'),
					to: {
						name: 'timeline',
						params: { type: 'liked' }
					}
				},
				{
					id: 'social-local',
					icon: 'icon-category-monitoring',
					title: t('social', 'Local timeline'),
					to: {
						name: 'timeline',
						params: { type: 'timeline' }
					}
				},
				{
					id: 'social-global',
					icon: 'icon-link',
					title: t('social', 'Global timeline'),
					to: {
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
			this.$store.commit('setServerData', JSON.parse(serverDataElmt.dataset.server))
		}

		if (!this.serverData.public) {
			this.search = new OCA.Search(this.search, this.resetSearch)
			this.$store.dispatch('fetchCurrentAccountInfo', this.cloudId)
		}

		if (OCA.Push && OCA.Push.isEnabled()) {
			OCA.Push.addCallback(this.fromPushApp, 'social')
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
		},
		search(term) {
			term = encodeURIComponent(term)
			this.searchTerm = term
		},
		resetSearch() {
			this.searchTerm = ''
		},
		fromPushApp: function(data) {
			// FIXME: might be better to use Timeline.type() ?
			let timeline = 'home'
			if (this.$route.name === 'tags') {
				timeline = 'tags'
			} else if (this.$route.params.type) {
				timeline = this.$route.params.type
			}

			if (data.source === 'timeline.home' && timeline === 'home') {
				this.$store.dispatch('addToTimeline', [data.payload])
			}
			if (data.source === 'timeline.direct' && timeline === 'direct') {
				this.$store.dispatch('addToTimeline', [data.payload])
			}
		}
	}
}
</script>

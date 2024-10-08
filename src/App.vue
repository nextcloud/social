<!--
  - SPDX-FileCopyrightText: 2018-2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent v-if="!serverData.setup" app-name="social" :class="{public: serverData.public}">
		<NcAppNavigation v-if="!serverData.public">
			<template #list>
				<NcAppNavigationItem v-for="item in menu.items"
					:key="item.key"
					:to="item.to"
					:title="item.title"
					:exact="true">
					<template #icon>
						<component :is="item.icon" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div v-if="serverData.isAdmin && !serverData.checks.success" class="setup social__wrapper">
				<h3 v-if="!serverData.checks.checks.wellknown">
					{{ t('social', '.well-known/webfinger isn\'t properly set up!') }}
				</h3>
				<p v-if="!serverData.checks.checks.wellknown">
					{{ t('social', 'Social needs the .well-known automatic discovery to be properly set up. If Nextcloud is not installed in the root of the domain, it is often the case that Nextcloud cannot configure this automatically. To use Social, the administrator of this Nextcloud instance needs to manually configure the .well-known redirects:') }} <a class="external_link"
						href="https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL"
						target="_blank"
						rel="noreferrer noopener">
						{{ t('social', 'Open documentation') }} ↗
					</a>
				</p>
			</div>
			<Search v-if="searchTerm !== ''" :term="searchTerm" />
			<router-view v-if="searchTerm === ''" :key="$route.fullPath" />
		</NcAppContent>
	</NcContent>
	<NcContent v-else app-name="social">
		<NcAppContent v-if="serverData.isAdmin" class="setup">
			<h2>{{ t('social', 'Social app setup') }}</h2>
			<p>{{ t('social', 'ActivityPub requires a fixed URL to make entries unique. Note that this cannot be changed later without resetting the Social app.') }}</p>
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
						{{ t('social', 'Social needs the .well-known automatic discovery to be properly set up. If Nextcloud is not installed in the root of the domain, it is often the case that Nextcloud cannot configure this automatically. To use Social, the administrator of this Nextcloud instance needs to manually configure the .well-known redirects:') }} <a class="external_link"
							href="https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL"
							target="_blank"
							rel="noreferrer noopener">
							{{ t('social', 'Open documentation') }} ↗
						</a>
					</p>
				</template>
			</form>
		</NcAppContent>
		<NcAppContent v-else class="setup">
			<p>{{ t('social', 'The Social app needs to be set up by the server administrator.') }}</p>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'

import Home from 'vue-material-design-icons/Home.vue'
import CommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import Bell from 'vue-material-design-icons/Bell.vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import Earth from 'vue-material-design-icons/Earth.vue'

import axios from '@nextcloud/axios'
import Search from './components/Search.vue'
import currentuserMixin from './mixins/currentUserMixin.js'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		Search,
	},
	mixins: [currentuserMixin],
	data() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
			searchTerm: '',
		}
	},
	computed: {
		/** @return {import('vue').PropType<import('../types/Mastodon.js').Account>} */
		timeline() {
			return this.$store.getters.getTimeline
		},
		/** @return {{items: {id: string, icon: object, title: string, to: { name: string } }, loading: boolean}} */
		menu() {
			const defaultCategories = [
				{
					id: 'social-timeline',
					icon: Home,
					title: t('social', 'Home'),
					to: {
						name: 'timeline',
					},
				},
				{
					id: 'social-direct-messages',
					icon: CommentAccount,
					title: t('social', 'Direct messages'),
					to: {
						name: 'timeline',
						params: { type: 'direct' },
					},
				},
				{
					id: 'social-notifications',
					icon: Bell,
					title: t('social', 'Notifications'),
					to: {
						name: 'timeline',
						params: { type: 'notifications' },
					},
				},
				{
					id: 'social-account',
					icon: Account,
					title: t('social', 'Profile'),
					to: {
						name: 'profile',
						params: { account: this.currentUser.uid },
					},
				},
				{
					id: 'social-liked',
					icon: Heart,
					title: t('social', 'Liked'),
					to: {
						name: 'timeline',
						params: { type: 'favourites' },
					},
				},
				{
					id: 'social-local',
					icon: AccountMultiple,
					title: t('social', 'Local timeline'),
					to: {
						name: 'timeline',
						params: { type: 'timeline' },
					},
				},
				{
					id: 'social-global',
					icon: Earth,
					title: t('social', 'Global timeline'),
					to: {
						name: 'timeline',
						params: { type: 'federated' },
					},
				},
			]
			return {
				items: defaultCategories,
				loading: false,
			}
		},
	},
	watch: {
		$route(to, from) {
			this.searchTerm = ''
		},
	},
	beforeMount() {
		// importing server data into the store
		this.$store.commit('setServerData', loadState('social', 'serverData'))

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
			axios.post(generateUrl('apps/social/api/v1/config/cloudAddress'), { cloudAddress: this.cloudAddress }).then((response) => {
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
		fromPushApp(data) {
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
		},
	},
}
</script>

<style scoped>
	#app-content-vue .social__wrapper {
		padding: 15px;
		max-width: 800px;
		margin: auto;
	}

	.setup {
		margin: 0 auto !important;
		padding: 15px;
		max-width: 800px;
	}

	.setup input[type=url] {
		width: 300px;
		max-width: 100%;
	}

	#social-spacer a:hover,
	#social-spacer a:focus {
		border: none !important;
	}

	a.external_link {
		text-decoration: underline;
	}

</style>
<style lang="css">
img.emoji {
	margin: 3px;
	width: 16px;
	vertical-align: text-bottom;
}
</style>

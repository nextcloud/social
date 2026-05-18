<template>
	<NcContent v-if="!serverData.setup" app-name="social" :class="{public: serverData.public}">
		<Navigation v-if="!serverData.public"
			@search="search"
			@open-composer="openComposer"
			@reset-cache="resetCache" />
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
			<router-view :key="$route.fullPath" />
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
						class="setup-input"
						required>
					<NcButton
						type="primary"
						native-type="submit">
						{{ t('social', 'Finish setup') }}
					</NcButton>
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
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'

import Navigation from './components/Navigation.vue'

import axios from '@nextcloud/axios'
import currentuserMixin from './mixins/currentUserMixin.js'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcButton,
		Navigation,
	},
	mixins: [currentuserMixin],
	data() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
		}
	},
	computed: {
	},
	watch: {
		$route() {
			this.$store.commit('setSearchQuery', '')
		},
	},
	beforeMount() {
		this.$store.commit('setServerData', loadState('social', 'serverData'))

		if (!this.serverData.public) {
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
		openComposer() {
			this.$store.commit('setComposerDisplayStatus', true)
			if (this.$route.name !== 'timeline') {
				this.$router.push({ name: 'timeline' })
			}
		},
		resetCache() {
			axios.post(generateUrl('apps/social/api/v1/cache/refresh')).then(() => {
				this.$store.dispatch('refreshTimeline')
			})
		},
		setCloudAddress() {
			axios.post(generateUrl('apps/social/api/v1/config/cloudAddress'), { cloudAddress: this.cloudAddress }).then((response) => {
				this.$store.commit('setServerDataEntry', 'setup', false)
				this.$store.commit('setServerDataEntry', 'cloudAddress', this.cloudAddress)
			})
		},
		search(term) {
			this.$store.commit('setSearchQuery', term)
		},
		fromPushApp(data) {
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

<style scoped lang="scss">
#app-content-vue .social__wrapper {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 800px;
	margin: auto;
}

.setup {
	margin: 0 auto !important;
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 800px;
	display: flex;
	flex-direction: column;
	gap: 20px;

	h2 {
		font-size: 24px;
		font-weight: 700;
		margin-bottom: 8px;
	}

	p {
		color: var(--color-text-lighter);
		line-height: 1.6;
	}
}

.setup-input {
	width: 300px;
	max-width: 100%;
	margin-right: 10px;
	border-radius: var(--border-radius-element);
}

#social-spacer a:hover,
#social-spacer a:focus {
	border: none !important;
}

a.external_link {
	text-decoration: underline;
}

:deep(.app-navigation-entry) {
	.app-navigation-entry__title {
		font-size: 14px;
	}
}

:deep(.app-navigation-entry__subname) {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: -2px;
}

:deep(.app-navigation-entry-icon) {
	display: flex;
	align-items: center;
	justify-content: center;

	.avatardiv {
		margin: 0;
	}
}

.icon-social {
	background-image: url('../img/social-dark.svg');
	filter: var(--background-invert-if-dark);
}
</style>
<style lang="scss">
img.emoji {
	margin: 3px;
	width: 16px;
	vertical-align: text-bottom;
}

.social__timeline {
	.social__wrapper {
		padding: 0;
		max-width: 600px;
		margin: 0 auto;
	}

	.timeline-entry {
		list-style: none;
	}
}

.list-enter-active, .list-leave-active {
	transition: opacity .15s ease;
}

.list-enter, .list-leave-to {
	opacity: 0;
}

.social__welcome {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: calc(var(--default-grid-baseline) * 4) auto;
	padding: calc(var(--default-grid-baseline) * 5);
	max-width: 600px;

	h2 {
		font-size: 22px;
		font-weight: 700;
		margin-bottom: 12px;
	}

	p {
		color: var(--color-text-lighter);
		line-height: 1.7;
	}
}

.new-post {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	padding: calc(var(--default-grid-baseline) * 3);
	max-width: 600px;
	position: sticky;
	top: 0;
	z-index: 100;
}

.app-navigation {
	.app-navigation-entry {
		.app-navigation-entry__title {
			font-size: 14px;
		}

		.app-navigation-entry__subname {
			font-size: 12px;
			color: var(--color-text-lighter);
		}
	}
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}


</style>

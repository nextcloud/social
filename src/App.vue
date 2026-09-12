<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent v-if="!serverData.setup" app-name="social" :class="{public: serverData.public}">
		<Navigation v-if="!serverData.public" @search="search" />
		<ShortcutHelp :open="shortcutHelpOpen" @close="shortcutHelpOpen = false" />
		<NcAppContent>
			<div v-if="serverData.isAdmin && !serverData.checks.success" class="setup social__wrapper">
				<SetupChecks :checks="serverData.checks.checks" :addresses="serverData.checks.addresses" />
			</div>
			<!-- not keyed on the full path: that remounted the whole view on
			     every route change, so opening a post and pressing Back
			     refetched page one and landed at the top of the timeline.
			     The views watch their own route params instead. -->
			<router-view />
		</NcAppContent>
	</NcContent>
	<NcContent v-else app-name="social">
		<NcAppContent v-if="serverData.isAdmin" class="setup">
			<h2>{{ t('social', 'Social app setup') }}</h2>
			<p>{{ t('social', 'ActivityPub requires a fixed URL to make entries unique. Note that this cannot be changed later without resetting the Social app.') }}</p>
			<form @submit.prevent="setCloudAddress">
				<p>
					<label class="hidden" for="setup-cloud-address">
						{{ t('social', 'ActivityPub URL base') }}
					</label>
					<input id="setup-cloud-address"
						v-model="cloudAddress"
						:placeholder="serverData.cliUrl"
						type="url"
						class="setup-input"
						required>
					<NcButton variant="primary"
						type="submit">
						{{ t('social', 'Finish setup') }}
					</NcButton>
				</p>
				<SetupChecks v-if="!serverData.checks.success"
					:checks="serverData.checks.checks"
					:addresses="serverData.checks.addresses" />
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
import ShortcutHelp from './components/ShortcutHelp.vue'
import SetupChecks from './components/SetupChecks.vue'
import { listenForShortcuts } from './services/shortcuts.js'
import eventBus from './services/eventBus.js'

import axios from '@nextcloud/axios'
import currentuserMixin from './mixins/currentUserMixin.js'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { useAccountStore } from './store/account.js'
import { useSettingsStore } from './store/settings.js'
import { useTimelineStore } from './store/timeline.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcButton,
		Navigation,
		ShortcutHelp,
		SetupChecks,
	},
	mixins: [currentuserMixin],
	data() {
		return {
			infoHidden: false,
			state: [],
			cloudAddress: '',
			shortcutHelpOpen: false,
			stopShortcuts: null,
		}
	},
	computed: {
		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),
	},
	mounted() {
		this.stopShortcuts = listenForShortcuts()
		eventBus.on('shortcut:help', this.toggleShortcutHelp)
		eventBus.on('shortcut:home', this.goHome)
	},
	unmounted() {
		this.stopShortcuts?.()
		eventBus.off('shortcut:help', this.toggleShortcutHelp)
		eventBus.off('shortcut:home', this.goHome)
	},
	watch: {
		$route(to) {
			// the query lives in the URL now; keep the store in step with it
			// so the navigation's search box shows what is being searched
			this.timelineStore.setSearchQuery(to.name === 'search' ? String(to.params.term ?? '') : '')
		},
	},
	beforeMount() {
		this.settingsStore.setServerData(loadState('social', 'serverData'))

		if (!this.serverData.public) {
			this.accountStore.fetchCurrentAccountInfo(this.cloudId)
		}

		if (OCA.Push && OCA.Push.isEnabled()) {
			OCA.Push.addCallback(this.fromPushApp, 'social')
		}
	},
	methods: {
		toggleShortcutHelp() {
			this.shortcutHelpOpen = !this.shortcutHelpOpen
		},
		goHome() {
			if (this.$route.name !== 'timeline' || this.$route.params.type) {
				this.$router.push({ name: 'timeline' })
			}
		},
		hideInfo() {
			this.infoHidden = true
		},
		setCloudAddress() {
			axios.post(generateUrl('apps/social/api/v1/config/cloudAddress'), { cloudAddress: this.cloudAddress }).then(() => {
				this.settingsStore.setServerDataEntry({ key: 'setup', value: false })
				this.settingsStore.setServerDataEntry({ key: 'cloudAddress', value: this.cloudAddress })
			})
		},
		/**
		 * Searching asks the server, on its own route.
		 *
		 * It used to commit the term to the store, where two getters filtered
		 * the ~15 statuses that happened to be loaded with String.includes —
		 * so a fresh timeline answered "No posts match your search" for posts
		 * this instance was holding.
		 *
		 * @param {string} term what was typed
		 */
		search(term) {
			const query = (term ?? '').trim()
			this.timelineStore.setSearchQuery(query)

			if (query === '') {
				if (this.$route.name === 'search') {
					this.$router.push({ name: 'timeline' })
				}
				return
			}

			// replace while the term is being refined, so Back does not have
			// to walk out through every keystroke
			const navigate = this.$route.name === 'search' ? this.$router.replace : this.$router.push
			navigate.call(this.$router, { name: 'search', params: { term: query } })
		},
		fromPushApp(data) {
			let timeline = 'home'
			if (this.$route.name === 'tags') {
				timeline = 'tags'
			} else if (this.$route.params.type) {
				timeline = this.$route.params.type
			}

			if (data.source === 'timeline.home' && timeline === 'home') {
				this.timelineStore.addToTimeline([data.payload])
			}
			if (data.source === 'timeline.direct' && timeline === 'direct') {
				this.timelineStore.addToTimeline([data.payload])
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
	margin-inline-end: 10px;
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
/**
 * Two levels of elevation, defined once, so every card in the app agrees about
 * what "resting" and "lifted" look like.
 *
 * Nextcloud's own --color-box-shadow is built for modals: rgba(77,77,77,.5) in
 * the light theme and solid black in the dark one. Used raw under a timeline it
 * would put a hard slab under every post, so it is thinned with color-mix and
 * split in two — a tight contact shadow that seats the card on the page, and a
 * wider ambient one that gives it depth. A browser without color-mix drops the
 * declaration and gets the borders, which is what the app looked like before.
 */
:root {
	--social-elevation-resting:
		0 1px 2px color-mix(in srgb, var(--color-box-shadow) 14%, transparent),
		0 2px 6px color-mix(in srgb, var(--color-box-shadow) 9%, transparent);
	--social-elevation-raised:
		0 2px 4px color-mix(in srgb, var(--color-box-shadow) 20%, transparent),
		0 6px 16px color-mix(in srgb, var(--color-box-shadow) 12%, transparent);

	/*
	 * One column, stated once. The composer and the timeline used to each
	 * carry their own max-width — the same number, but the list also had a
	 * horizontal padding and the composer did not, so the two boxes were a
	 * gutter's width apart at both edges and nothing on the page lined up.
	 * Anything that sits in the column is `--social-column` wide. The list
	 * keeps a gutter inside that, so a post is narrower than the column by
	 * `--social-column-gutter` on each side and the composer, which takes the
	 * column whole, stands that much proud of the posts beneath it.
	 */
	--social-column: 900px;
	--social-column-gutter: calc(var(--default-grid-baseline, 4px) * 2);
}

img.emoji {
	margin: 3px;
	width: 16px;
	vertical-align: text-bottom;
}

.social__timeline {
	.social__wrapper {
		padding: 0;
		max-width: var(--social-column);
		margin: 0 auto;
	}

	.timeline-entry {
		list-style: none;
	}
}

/**
 * The transition both timeline <transition-group>s use, defined once here
 * because this style block is global. Vue 3 names the starting class
 * `-enter-from` (Vue 2 called it `-enter`, which never matched and left the
 * enter animation dead), and `-move` is what makes the surrounding entries
 * slide when one is inserted or removed instead of jumping.
 */
.list-enter-active,
.list-leave-active,
.list-move {
	transition: opacity .2s ease, transform .2s ease;
}

.list-enter-from,
.list-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
	.list-enter-active,
	.list-leave-active,
	.list-move {
		transition: none;
	}
}

/**
 * Posts ease in as they scroll into view. The browser drives this off the
 * scroll position on the compositor — no scroll listener, no observer, no
 * work on the main thread — and where the property is missing nothing
 * happens at all, which is why it needs no fallback.
 */
@supports (animation-timeline: view()) {
	@media (prefers-reduced-motion: no-preference) {
		@keyframes timeline-entry-rise {
			from {
				opacity: 0;
				transform: translateY(12px) scale(.99);
			}

			to {
				opacity: 1;
				transform: none;
			}
		}

		.social__timeline .timeline-entry {
			animation: timeline-entry-rise linear both;
			animation-timeline: view();
			/* only the arrival is animated, not the departure */
			animation-range: entry 0% entry 45%;
		}
	}
}

.social__welcome {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: calc(var(--default-grid-baseline) * 4) auto;
	padding: calc(var(--default-grid-baseline) * 5);
	max-width: var(--social-column);

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
	max-width: var(--social-column);
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

<style>
/* remote custom emoji rendered inline in post content and display names */
img.custom-emoji {
	height: 1.25em;
	width: auto;
	vertical-align: text-bottom;
	object-fit: contain;
}
</style>

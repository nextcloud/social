<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="settings">
		<h2 class="settings__heading">
			{{ t('social', 'Settings') }}
		</h2>

		<!-- A list of sections. Migration was the second of them; it used to be
		     a page of its own with an entry in the account menu, and that menu
		     is for places to read something rather than things you do to the
		     account.

		     Each carries an id, because other pages link to one of them: the
		     follow requests page sends the reader to #account for the switch
		     its empty state talks about. -->
		<section id="account" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Your account') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'How you are named, how others find you, and who sees what you post.') }}
			</p>
			<AccountSettings />
		</section>

		<section id="featured-tags" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Featured hashtags') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'The hashtags you want your profile to be known for. They sit under your bio, and anybody can click one to read what you posted with it.') }}
			</p>
			<FeaturedTagsSettings />
		</section>

		<section id="lists" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Lists') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'A list is a few of the people you follow, read as a timeline of its own. Your lists are in the sidebar.') }}
			</p>
			<ListsSettings />
		</section>

		<section id="shortcuts" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Keyboard shortcuts') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'The keys this app listens for while you are reading.') }}
			</p>
			<ShortcutList />
		</section>

		<section id="migration" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Scheduled posts') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'What you have written to be published later. Cancel one here; to change its time, write it again.') }}
			</p>
			<ScheduledPosts />
		</section>

		<section id="recap" class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Looking back') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'Posts you wrote on this day in earlier years appear at the top of your feed on their own. This is the other half: a note about the week just gone, if you want one.') }}
			</p>
			<RecapSettings />
		</section>

		<section class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Migration') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'Your account is yours. Take a copy of it whenever you like, move it to another server, or bring an account here from somewhere else.') }}
			</p>
			<MigrationSettings />
		</section>
	</div>
</template>

<script>
import MigrationSettings from '../components/MigrationSettings.vue'
import ScheduledPosts from '../components/ScheduledPosts.vue'
import ShortcutList from '../components/ShortcutList.vue'
import { defineAsyncComponent } from 'vue'
import { t } from '@nextcloud/l10n'

// Two forms of some size that nobody sees until they open this page, so they
// travel in a chunk of their own rather than in the entry every reader loads.
const AccountSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/AccountSettings.vue'))
// in the same chunk as the two forms above, and for the same reason: it is a
// switch nobody sees until they open this page, and it brings a form control
// of its own with it
const RecapSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/RecapSettings.vue'))
const ListsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/ListsSettings.vue'))
// same chunk again: a form and a text field nobody sees until they open this page
const FeaturedTagsSettings = defineAsyncComponent(() => import(/* webpackChunkName: "settings" */'../components/FeaturedTagsSettings.vue'))

/**
 * Settings: what this app holds about how the reader uses it.
 *
 * A page rather than a dialog. The shortcuts were already a dialog — `?` opens
 * it over whatever you were reading, which is right for a reminder mid-scroll
 * and wrong for a place you go to change things. What arrives here later is
 * another section, so the page is a list of sections from the start rather
 * than a page about shortcuts that has to be taken apart the first time it
 * gains a second subject.
 */
export default {
	name: 'Settings',

	components: {
		AccountSettings,
		FeaturedTagsSettings,
		ListsSettings,
		MigrationSettings,
		RecapSettings,
		ScheduledPosts,
		ShortcutList,
	},

	data() {
		return {
			/** the section already scrolled to, so it is not chased twice */
			scrolledTo: '',
		}
	},

	mounted() {
		this.scrollToSection()
	},

	updated() {
		// the sections arrive after their chunk does, so a link to one of them
		// lands on a page that does not have it yet
		this.scrollToSection()
	},

	methods: {
		t,

		/**
		 * Puts the section named in the address in view. Vue Router leaves the
		 * hash alone on a page that is already mounted, and the section a link
		 * points at may not have been drawn when the page first was.
		 */
		scrollToSection() {
			const id = (this.$route?.hash ?? '').replace(/^#/, '')
			if (id === '' || this.scrolledTo === id) {
				return
			}
			const section = document.getElementById(id)
			if (!section || typeof section.scrollIntoView !== 'function') {
				return
			}
			this.scrolledTo = id
			const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
			section.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' })
		},
	},
}
</script>

<style scoped lang="scss">
.settings {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 760px;
	margin-inline: auto;

	&__heading {
		margin-bottom: calc(var(--default-grid-baseline) * 4);
	}

	&__section + &__section {
		margin-top: calc(var(--default-grid-baseline) * 4);
	}

	&__section {
		padding: calc(var(--default-grid-baseline) * 4);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
	}

	&__section-heading {
		margin: 0;
		font-size: 17px;
	}

	&__section-lede {
		margin: 4px 0 16px;
		color: var(--color-text-maxcontrast);
	}
}
</style>

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
		     account. -->
		<section class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Keyboard shortcuts') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'The keys this app listens for while you are reading.') }}
			</p>
			<ShortcutList />
		</section>

		<section class="settings__section">
			<h3 class="settings__section-heading">
				{{ t('social', 'Scheduled posts') }}
			</h3>
			<p class="settings__section-lede">
				{{ t('social', 'What you have written to be published later. Cancel one here; to change its time, write it again.') }}
			</p>
			<ScheduledPosts />
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
import { t } from '@nextcloud/l10n'

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
		MigrationSettings,
		ScheduledPosts,
		ShortcutList,
	},

	methods: {
		t,
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

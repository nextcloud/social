<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shortcuts-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="enabled"
			:disabled="saving"
			@update:modelValue="setEnabled">
			{{ t('social', 'Use keyboard shortcuts') }}
		</NcCheckboxRadioSwitch>
		<p class="shortcuts-settings__hint">
			{{ enabled
				? t('social', 'On: a single key likes, boosts or opens the post you moved to with j or k.')
				: t('social', 'Off: keys do nothing here except what your browser does with them.') }}
		</p>
		<p v-if="offInNextcloud" class="shortcuts-settings__hint">
			{{ t('social', 'Keyboard shortcuts are turned off for all of Nextcloud in your Accessibility settings, so they stay off here whatever this switch says.') }}
		</p>
		<ShortcutList />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ShortcutList from './ShortcutList.vue'
import logger from '../services/logger.js'
import { offInNextcloud } from '../services/shortcuts.js'
import { showError } from '../services/toast.js'
import { useSettingsStore } from '../store/settings.js'

/**
 * The switch for the one-key shortcuts, above the list of them. Kept with the
 * account rather than in the browser, so turning them off holds wherever the
 * reader signs in. Nextcloud's own Accessibility setting turns them off too.
 */
export default {
	name: 'ShortcutsSettings',

	components: {
		NcCheckboxRadioSwitch,
		ShortcutList,
	},

	data() {
		return {
			saving: false,
			offInNextcloud: offInNextcloud(),
		}
	},

	computed: {
		enabled() {
			return useSettingsStore().shortcutsEnabled
		},
	},

	methods: {
		t,

		/** @param {boolean} enabled the new position */
		async setEnabled(enabled) {
			const settingsStore = useSettingsStore()
			const before = settingsStore.shortcutsEnabled
			settingsStore.setServerDataEntry({ key: 'keyboardShortcuts', value: enabled })
			this.saving = true
			try {
				const { data } = await axios.patch(generateUrl('apps/social/api/v1/social/keyboard_shortcuts'), { enabled })
				settingsStore.setServerDataEntry({ key: 'keyboardShortcuts', value: data?.enabled !== false })
			} catch (error) {
				logger.error('Could not save whether the keyboard shortcuts are on', { error })
				showError(t('social', 'Could not save that setting'))
				settingsStore.setServerDataEntry({ key: 'keyboardShortcuts', value: before })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.shortcuts-settings__hint {
	margin: 4px 0 16px;
	color: var(--color-text-maxcontrast);
}
</style>

<!--
 - SPDX-FileCopyrightText: 2026 Aloha Social contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="bluesky-display-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="hidden"
			:disabled="saving"
			@update:modelValue="setHidden">
			{{ t('social', 'Hide Bluesky verification badges') }}
		</NcCheckboxRadioSwitch>
		<p class="bluesky-display-settings__hint">
			{{ t('social', 'This hides verification badges from Bluesky and its trusted verifiers. Checks made by this server remain visible. Bluesky apps signed in to this account use the same setting.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { useSettingsStore } from '../store/settings.js'

export default {
	name: 'BlueskyDisplaySettings',

	components: { NcCheckboxRadioSwitch },

	data() {
		return { saving: false }
	},

	computed: {
		hidden() {
			return useSettingsStore().getServerData?.bluesky?.hideBadges === true
		},
	},

	methods: {
		t,

		async setHidden(hideBadges) {
			const store = useSettingsStore()
			const before = store.getServerData?.bluesky?.hideBadges === true
			store.setServerDataEntry({ key: 'bluesky', value: { ...store.getServerData.bluesky, hideBadges } })
			this.saving = true
			try {
				const { data } = await axios.put(generateUrl('apps/social/api/v1/social/bluesky/verification-preferences'), { hideBadges })
				store.setServerDataEntry({ key: 'bluesky', value: { ...store.getServerData.bluesky, hideBadges: data?.hideBadges === true } })
			} catch (error) {
				logger.error('Could not save Bluesky verification preferences', { error })
				showError(t('social', 'Could not save that setting'))
				store.setServerDataEntry({ key: 'bluesky', value: { ...store.getServerData.bluesky, hideBadges: before } })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bluesky-display-settings__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>

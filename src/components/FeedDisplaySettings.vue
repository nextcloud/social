<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="feed-display-settings">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="!hidden"
			:disabled="saving"
			@update:modelValue="showReplies">
			{{ t('social', 'Show replies as separate feed posts') }}
		</NcCheckboxRadioSwitch>
		<p class="feed-display-settings__hint">
			{{ hidden
				? t('social', 'Replies stay in their conversations, but do not appear as separate cards in your home, public or hashtag feeds.')
				: t('social', 'Replies can appear as separate cards in your feeds. Turn this off to keep the feed focused on standalone posts.') }}
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
	name: 'FeedDisplaySettings',

	components: {
		NcCheckboxRadioSwitch,
	},

	data() {
		return { saving: false }
	},

	computed: {
		hidden() {
			return useSettingsStore().hidesRepliesInFeeds
		},
	},

	methods: {
		t,

		async showReplies(visible) {
			const settingsStore = useSettingsStore()
			const before = settingsStore.hidesRepliesInFeeds
			settingsStore.setServerDataEntry({ key: 'hideRepliesInFeeds', value: !visible })
			this.saving = true
			try {
				const { data } = await axios.patch(generateUrl('apps/social/api/v1/social/feed_preferences'), { hide_replies: !visible })
				settingsStore.setServerDataEntry({ key: 'hideRepliesInFeeds', value: data?.hide_replies === true })
			} catch (error) {
				logger.error('Could not save whether replies are shown in feeds', { error })
				showError(t('social', 'Could not save that setting'))
				settingsStore.setServerDataEntry({ key: 'hideRepliesInFeeds', value: before })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.feed-display-settings__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>

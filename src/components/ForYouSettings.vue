<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="for-you-settings">
		<NcNoteCard type="info">
			<template v-if="recommendationsEnabled && legacyEnabled">
				{{ t('social', 'There is one For You feed. Before you choose topics, it uses your hashtag interests as a familiar starting point. Once you save topic choices, those choices shape the ranked feed. Hashtags remain available for the administrator’s compatibility fallback; posts are never mixed from two For You feeds.') }}
			</template>
			<template v-else-if="recommendationsEnabled">
				{{ t('social', 'There is one ranked For You feed. Choose at least one topic to start it. Hashtag fallback is disabled for this instance, so an empty topic selection shows no posts.') }}
			</template>
			<template v-else>
				{{ t('social', 'For You currently uses the familiar hashtag feed. The administrator has not enabled ranked topic recommendations for this instance.') }}
			</template>
		</NcNoteCard>

		<RecommendationTopicsSettings v-if="recommendationsEnabled" />

		<details v-if="recommendationsEnabled && legacyEnabled" class="for-you-settings__legacy">
			<summary>{{ t('social', 'Hashtag starting point and compatibility fallback') }}</summary>
			<p class="for-you-settings__explanation">
				{{ t('social', 'These hashtags power the familiar feed before you have saved any topic choices. They are also available if an administrator temporarily switches the instance to the compatibility feed. Your saved topics take over once you choose them.') }}
			</p>
			<InterestsSettings legacyFallback />
		</details>

		<InterestsSettings v-else-if="legacyEnabled" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { useServerData } from '../composables/useServerData.js'
import InterestsSettings from './InterestsSettings.vue'
import RecommendationTopicsSettings from './RecommendationTopicsSettings.vue'

export default {
	name: 'ForYouSettings',

	components: {
		InterestsSettings,
		NcNoteCard,
		RecommendationTopicsSettings,
	},

	setup() {
		const { serverData } = useServerData()
		return { serverData }
	},

	computed: {
		recommendationsEnabled() {
			return this.serverData?.interests?.recommendationsEnabled === true
		},

		legacyEnabled() {
			return this.serverData?.interests?.enabled === true
		},
	},

	methods: { t },
}
</script>

<style lang="scss" scoped>
.for-you-settings {
	&__legacy {
		margin-block: 1.5rem;
		padding: 0.75rem 1rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);

		summary {
			min-height: 44px;
			display: flex;
			align-items: center;
			font-weight: 600;
			cursor: pointer;
		}
	}

	&__explanation {
		max-width: 72ch;
		color: var(--color-text-maxcontrast);
	}
}
</style>

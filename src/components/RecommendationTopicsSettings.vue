<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="recommendation-topics">
		<p class="recommendation-topics__hint">
			{{ t('social', 'Choose topics you want to prioritise or hide. A hidden parent also hides its subtopics. Choices are private to your account and do not turn on activity tracking.') }}
		</p>
		<p v-if="loading" class="recommendation-topics__status" role="status">
			{{ t('social', 'Loading topics…') }}
		</p>
		<template v-else>
			<ul class="recommendation-topics__list">
				<li
					v-for="topic in topics"
					:key="topic.id"
					class="recommendation-topics__item"
					:class="{ 'recommendation-topics__item--child': topic.parentId }">
					<label :for="`recommendation-topic-${topic.id}`">{{ labelOf(topic) }}</label>
					<select :id="`recommendation-topic-${topic.id}`" v-model="draft[topic.id]" :disabled="saving">
						<option value="">
							{{ t('social', 'No preference') }}
						</option>
						<option v-for="mode in MODES" :key="mode.value" :value="mode.value">
							{{ mode.label() }}
						</option>
					</select>
					<small v-if="inheritedHide(topic.id)" class="recommendation-topics__inheritance">
						{{ t('social', 'Hidden by a parent topic') }}
					</small>
				</li>
			</ul>
			<div class="recommendation-topics__actions">
				<NcButton variant="primary" :disabled="saving || !dirty" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Save topic choices') }}
				</NcButton>
				<NcButton variant="error" :disabled="saving || topics.length === 0" @click="confirmingReset = true">
					{{ t('social', 'Reset topic choices') }}
				</NcButton>
				<NcButton :disabled="saving" @click="exportPreferences">
					{{ t('social', 'Export choices') }}
				</NcButton>
			</div>
			<p class="recommendation-topics__hint">
				{{ t('social', 'Revision {revision}. The feed is not active in this foundation release; your choices are saved for the later ranking stage.', { revision }) }}
			</p>
		</template>
		<NcDialog
			:open="confirmingReset"
			:name="t('social', 'Reset topic choices?')"
			:buttons="resetButtons"
			@update:open="confirmingReset = $event">
			<p>{{ t('social', 'This clears only the topic choices saved for For You. Follows, mutes, blocks and the existing hashtag feed are unchanged.') }}</p>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorOf } from '../utils/serverError.js'
import { showError, showSuccess } from '../services/toast.js'

const MODES = [
	{ value: 'HIDE', label: () => t('social', 'Hide this topic') },
	{ value: 'LESS', label: () => t('social', 'Show less') },
	{ value: 'NEUTRAL', label: () => t('social', 'No ranking preference') },
	{ value: 'INTERESTED', label: () => t('social', 'Interested') },
	{ value: 'PRIORITISE', label: () => t('social', 'Prioritise') },
]

const catalogUrl = () => generateUrl('apps/social/api/v1/recommendations/catalog')
const preferencesUrl = () => generateUrl('apps/social/api/v1/recommendations/preferences')
const resetUrl = () => generateUrl('apps/social/api/v1/recommendations/reset')
const exportUrl = () => generateUrl('apps/social/api/v1/recommendations/export')

export default {
	name: 'RecommendationTopicsSettings',
	components: { NcButton, NcDialog, NcLoadingIcon },
	data() {
		return {
			MODES,
			topics: [],
			revision: 0,
			generation: 0,
			stateTopics: [],
			draft: {},
			loading: true,
			saving: false,
			confirmingReset: false,
		}
	},

	computed: {
		dirty() {
			return this.topics.some((topic) => (this.draft[topic.id] || '') !== (this.saved[topic.id] || ''))
		},

		saved() {
			return Object.fromEntries((this.stateTopics || []).map((entry) => [entry.topic_id, entry.mode]))
		},

		resetButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.confirmingReset = false } },
				{ label: t('social', 'Reset topic choices'), variant: /** @type {'error'} */ ('error'), disabled: this.saving, callback: () => this.reset() },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		labelOf(topic) {
			const language = (getLanguage() || 'en').toLowerCase().slice(0, 2)
			return topic.labels?.[language] || topic.labels?.en || topic.id
		},

		async load() {
			this.loading = true
			try {
				const [catalogResponse, preferencesResponse] = await Promise.all([axios.get(catalogUrl()), axios.get(preferencesUrl())])
				this.topics = catalogResponse.data?.topics ?? []
				this.applyState(preferencesResponse.data)
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not load your For You topic choices')))
			} finally {
				this.loading = false
			}
		},

		applyState(state = {}) {
			this.revision = Number(state.revision) || 0
			this.generation = Number(state.generation) || 0
			this.stateTopics = Array.isArray(state.topics) ? state.topics : []
			this.draft = Object.fromEntries(this.stateTopics.map((entry) => [entry.topic_id, entry.mode]))
		},

		inheritedHide(topicId) {
			return this.topics.some((topic) => topic.id === topicId && this.draft[topic.parentId] === 'HIDE')
		},

		async save() {
			this.saving = true
			try {
				const topics = Object.entries(this.draft)
					.filter(([, mode]) => mode)
					.map(([topicId, mode]) => ({ topic_id: topicId, mode }))
				const { data } = await axios.put(preferencesUrl(), { if_revision: this.revision, topics })
				this.applyState(data)
				showSuccess(t('social', 'Topic choices saved'))
			} catch (error) {
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyState(current)
				}
				showError(errorOf(error, error?.response?.status === 409
					? t('social', 'Your topic choices changed in another session. The latest choices have been loaded.')
					: t('social', 'Could not save your topic choices')))
			} finally {
				this.saving = false
			}
		},

		async reset() {
			this.confirmingReset = false
			this.saving = true
			try {
				const { data } = await axios.post(resetUrl(), { if_revision: this.revision })
				this.applyState(data)
				showSuccess(t('social', 'Topic choices reset'))
			} catch (error) {
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyState(current)
				}
				showError(errorOf(error, t('social', 'Could not reset your topic choices')))
			} finally {
				this.saving = false
			}
		},

		async exportPreferences() {
			try {
				const { data } = await axios.get(exportUrl())
				const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
				const link = document.createElement('a')
				link.href = url
				link.download = 'aloha-social-topic-choices.json'
				link.click()
				URL.revokeObjectURL(url)
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not export your topic choices')))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.recommendation-topics {
	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 70ch;
	}

	&__list {
		list-style: none;
		padding: 0;
		margin: 1rem 0;
	}

	&__item {
		display: grid;
		grid-template-columns: minmax(12rem, 1fr) minmax(12rem, 20rem);
		gap: 0.75rem;
		align-items: center;
		padding: 0.65rem 0;
		border-bottom: 1px solid var(--color-border);

		&--child {
			padding-inline-start: 1.5rem;
		}
	}

	&__item select {
		width: 100%;
	}

	&__inheritance {
		grid-column: 2;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem;
		margin-block: 1rem;
	}

	@media (max-width: 700px) {
		&__item {
			grid-template-columns: 1fr;
		}

		&__inheritance {
			grid-column: 1;
		}
	}
}
</style>

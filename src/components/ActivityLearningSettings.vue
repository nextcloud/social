<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="activity-learning" aria-labelledby="activity-learning-title">
		<h3 id="activity-learning-title">
			{{ t('social', 'Activity learning') }}
		</h3>
		<p class="activity-learning__hint">
			{{ t('social', 'When enabled, this account uses likes, saves, replies, qualified views, video watching and profile visits to refine the automatic For You feed. Reply text is never analysed. The profile stays on this server and is not shared with other people.') }}
		</p>
		<p v-if="loading" class="activity-learning__status" role="status">
			{{ t('social', 'Loading activity learning settings…') }}
		</p>
		<div v-else class="activity-learning__controls">
			<label for="activity-learning-mode">{{ t('social', 'Learning mode') }}</label>
			<select id="activity-learning-mode" v-model="learningMode" :disabled="saving">
				<option value="off">
					{{ t('social', 'Off') }}
				</option>
				<option value="on">
					{{ t('social', 'On') }}
				</option>
				<option value="paused">
					{{ t('social', 'Paused') }}
				</option>
			</select>
			<NcButton :disabled="saving || learningMode === savedLearningMode" @click="saveLearningMode">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save learning mode') }}
			</NcButton>
			<NcButton variant="error" :disabled="saving" @click="confirmingReset = true">
				{{ t('social', 'Reset learned signals') }}
			</NcButton>
		</div>
		<p v-if="!loading" class="activity-learning__hint">
			{{ t('social', 'Pausing keeps the current activity profile available. Turning learning off stops using behavioral signals. Reset removes those signals and impressions; your automatic hashtag profile remains available.') }}
		</p>
		<NcDialog
			:open="confirmingReset"
			:name="t('social', 'Reset learned signals?')"
			:buttons="resetButtons"
			@update:open="confirmingReset = $event">
			<p>{{ t('social', 'This clears the learned activity profile, impression history and pending event IDs. Your automatic hashtag profile, follows, mutes and blocks are kept.') }}</p>
		</NcDialog>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorOf } from '../utils/serverError.js'
import { showError, showSuccess } from '../services/toast.js'

const preferencesUrl = () => generateUrl('apps/social/api/v1/recommendations/preferences')
const resetUrl = () => generateUrl('apps/social/api/v1/recommendations/reset')
const learningUrl = () => generateUrl('apps/social/api/v1/recommendations/learning')

export default {
	name: 'ActivityLearningSettings',
	components: { NcButton, NcDialog, NcLoadingIcon },

	data() {
		return {
			revision: 0,
			learningMode: 'off',
			savedLearningMode: 'off',
			loading: true,
			saving: false,
			confirmingReset: false,
		}
	},

	computed: {
		resetButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.confirmingReset = false } },
				{ label: t('social', 'Reset learned signals'), variant: /** @type {'error'} */ ('error'), disabled: this.saving, callback: () => this.resetLearning() },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		applyState(state = {}) {
			this.revision = Number(state.revision) || 0
			this.learningMode = ['off', 'on', 'paused'].includes(state.learning_mode) ? state.learning_mode : 'off'
			this.savedLearningMode = this.learningMode
		},

		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(preferencesUrl())
				this.applyState(data)
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not load activity learning settings')))
			} finally {
				this.loading = false
			}
		},

		async saveLearningMode() {
			this.saving = true
			try {
				const { data } = await axios.put(learningUrl(), { if_revision: this.revision, mode: this.learningMode })
				this.applyState(data)
				showSuccess(t('social', 'Activity learning setting saved'))
			} catch (error) {
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyState(current)
				}
				showError(errorOf(error, t('social', 'Could not save the activity learning setting')))
			} finally {
				this.saving = false
			}
		},

		async resetLearning() {
			this.confirmingReset = false
			this.saving = true
			try {
				const { data } = await axios.post(resetUrl(), { if_revision: this.revision, scope: 'learning' })
				this.applyState(data)
				showSuccess(t('social', 'Learned signals reset'))
			} catch (error) {
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyState(current)
				}
				showError(errorOf(error, t('social', 'Could not reset learned signals')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.activity-learning {
	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__status {
		color: var(--color-text-maxcontrast);
	}

	&__controls {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 0.5rem;

		label {
			font-weight: 600;
		}
	}
}
</style>

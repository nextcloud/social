<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="recommendation-topics">
		<p class="recommendation-topics__hint">
			{{ t('social', 'Choose topics you want to prioritise or hide. A hidden parent also hides its subtopics. Topic choices are private to your account and do not turn on activity tracking.') }}
		</p>
		<p v-if="loading" class="recommendation-topics__status" role="status">
			{{ t('social', 'Loading topics…') }}
		</p>
		<template v-else>
			<section v-if="!onboardingComplete && !automaticProfile" class="recommendation-topics__onboarding" aria-labelledby="recommendation-onboarding-title">
				<h3 id="recommendation-onboarding-title">
					{{ t('social', 'Set up your For You feed') }}
				</h3>
				<p class="recommendation-topics__hint">
					{{ t('social', 'Choose what matters to you. Every step is optional, your choices stay private, and you can change them later.') }}
				</p>
				<ol class="recommendation-topics__steps" :aria-label="t('social', 'For You setup progress')">
					<li v-for="(label, index) in onboardingSteps" :key="label" :aria-current="wizardStep === index ? 'step' : undefined">
						{{ label }}
					</li>
				</ol>
				<section v-if="wizardStep === 0" aria-labelledby="recommendation-onboarding-topics">
					<h4 id="recommendation-onboarding-topics">
						{{ t('social', 'Choose topics') }}
					</h4>
					<p>{{ t('social', 'Pick broad subjects you want in your feed. You can choose none and adjust this later.') }}</p>
					<div class="recommendation-topics__choices">
						<label v-for="topic in rootTopics" :key="topic.id" class="recommendation-topics__choice">
							<input type="checkbox" :checked="topicSelected(topic.id)" @change="toggleTopic(topic.id, $event)">
							{{ labelOf(topic) }}
						</label>
					</div>
				</section>
				<section v-else-if="wizardStep === 1" aria-labelledby="recommendation-onboarding-languages">
					<h4 id="recommendation-onboarding-languages">
						{{ t('social', 'Choose languages') }}
					</h4>
					<p>{{ t('social', 'No selection means posts in every language. Language choices are a feed filter, not a translation setting.') }}</p>
					<div class="recommendation-topics__choices">
						<label v-for="language in languageOptions" :key="language.code" class="recommendation-topics__choice">
							<input
								v-model="selectedLanguages"
								type="checkbox"
								:value="language.code"
								:disabled="selectedLanguages.length >= 20 && !selectedLanguages.includes(language.code)">
							{{ language.label }}
						</label>
						<label class="recommendation-topics__choice">
							<input type="checkbox" :checked="selectedLanguages.length === 0" @change="selectedLanguages = []">
							{{ t('social', 'All languages') }}
						</label>
					</div>
					<div class="recommendation-topics__language-entry">
						<label for="recommendation-language-code">{{ t('social', 'Add another language code') }}</label>
						<div class="recommendation-topics__language-entry-controls">
							<input
								id="recommendation-language-code"
								v-model.trim="languageInput"
								:disabled="selectedLanguages.length >= 20"
								:placeholder="t('social', 'For example, fr or pt-BR')"
								:aria-describedby="languageError ? 'recommendation-language-error' : undefined"
								@input="validateLanguageInput"
								@keyup.enter.prevent="addLanguage">
							<NcButton :disabled="!canAddLanguage" @click="addLanguage">
								{{ t('social', 'Add language') }}
							</NcButton>
						</div>
						<p
							v-if="languageError"
							id="recommendation-language-error"
							class="recommendation-topics__error"
							role="alert">
							{{ languageError }}
						</p>
						<p class="recommendation-topics__hint">
							{{ t('social', 'Posts with an unknown language remain eligible; up to 20 language codes can be selected.') }}
						</p>
					</div>
				</section>
				<section v-else-if="wizardStep === 2" aria-labelledby="recommendation-onboarding-balance">
					<h4 id="recommendation-onboarding-balance">
						{{ t('social', 'Choose your content balance') }}
					</h4>
					<p>{{ t('social', 'This controls how often a carefully matched post from a new author can appear. It never overrides topic hides, moderation or visibility rules.') }}</p>
					<div class="recommendation-topics__choices recommendation-topics__choices--column">
						<label v-for="choice in balanceOptions" :key="choice.value" class="recommendation-topics__choice">
							<input
								v-model="draftContentBalance"
								type="radio"
								name="recommendation-content-balance"
								:value="choice.value">
							<span><strong>{{ choice.label }}</strong><small>{{ choice.description }}</small></span>
						</label>
					</div>
				</section>
				<section v-else aria-labelledby="recommendation-onboarding-review">
					<h4 id="recommendation-onboarding-review">
						{{ t('social', 'Review and start') }}
					</h4>
					<p>{{ t('social', 'You can edit these choices any time in Settings. Activity learning is separate and stays off until you turn it on.') }}</p>
					<dl class="recommendation-topics__review">
						<div><dt>{{ t('social', 'Topics') }}</dt><dd>{{ selectedTopicLabels || t('social', 'No topics selected') }}</dd></div>
						<div><dt>{{ t('social', 'Languages') }}</dt><dd>{{ selectedLanguageLabels }}</dd></div>
						<div><dt>{{ t('social', 'Content balance') }}</dt><dd>{{ selectedBalanceLabel }}</dd></div>
					</dl>
				</section>
				<div class="recommendation-topics__actions">
					<NcButton v-if="wizardStep > 0" :disabled="saving" @click="backWizard">
						{{ t('social', 'Back') }}
					</NcButton>
					<NcButton
						v-if="wizardStep < 3"
						variant="primary"
						:disabled="saving"
						@click="advanceWizard">
						<template v-if="saving" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Continue') }}
					</NcButton>
					<NcButton v-if="wizardStep < 3" :disabled="saving" @click="advanceWizard">
						{{ t('social', 'Skip this step') }}
					</NcButton>
					<NcButton
						v-else
						variant="primary"
						:disabled="saving"
						@click="completeWizard">
						<template v-if="saving" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Finish setup') }}
					</NcButton>
				</div>
			</section>
			<template v-else>
				<section class="recommendation-topics__learning" aria-labelledby="recommendation-learning-title">
					<h3 id="recommendation-learning-title">
						{{ t('social', 'Activity learning') }}
					</h3>
					<p class="recommendation-topics__hint">
						{{ t('social', 'When enabled, this account uses likes, saves, replies, qualified views, video watching and profile visits to adjust future For You sessions. Reply text is never analysed. The profile stays on this server and is not shared with other people.') }}
					</p>
					<div class="recommendation-topics__learning-controls">
						<label for="recommendation-learning-mode">{{ t('social', 'Learning mode') }}</label>
						<select id="recommendation-learning-mode" v-model="learningMode" :disabled="saving || dirty">
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
						<NcButton :disabled="saving || dirty || learningMode === savedLearningMode" @click="saveLearningMode">
							{{ t('social', 'Save learning mode') }}
						</NcButton>
						<NcButton variant="error" :disabled="saving" @click="confirmingLearningReset = true">
							{{ t('social', 'Reset learned signals') }}
						</NcButton>
					</div>
					<p class="recommendation-topics__hint">
						{{ automaticProfile
							? t('social', 'Pausing keeps the current profile available. Turning learning off stops using behavioral signals. Reset removes those signals and impressions; the automatic hashtag profile remains available.')
							: t('social', 'Pausing stops new signals and keeps the learned profile available. Turning learning off stops using the profile. Reset removes learned signals and impressions but keeps these topic choices.') }}
					</p>
				</section>
				<section v-if="!automaticProfile" class="recommendation-topics__balance" aria-labelledby="recommendation-content-balance-title">
					<h3 id="recommendation-content-balance-title">
						{{ t('social', 'Content balance') }}
					</h3>
					<p class="recommendation-topics__hint">
						{{ t('social', 'Choose how often carefully matched posts from new authors can appear. This never overrides your topic choices or safety controls.') }}
					</p>
					<select v-model="draftContentBalance" :disabled="saving" :aria-label="t('social', 'Content balance')">
						<option v-for="choice in balanceOptions" :key="choice.value" :value="choice.value">
							{{ choice.label }}
						</option>
					</select>
				</section>
				<div v-if="!automaticProfile" class="recommendation-topics__browser">
					<NcTextField
						v-model="topicSearch"
						class="recommendation-topics__search"
						type="search"
						:label="t('social', 'Find a topic')"
						trailingButtonIcon="close"
						:showTrailingButton="topicSearch !== ''"
						@trailingButtonClick="topicSearch = ''" />
					<p class="recommendation-topics__hint" role="status">
						{{ n('social', '%n topic available', '%n topics available', topics.length) }}
					</p>
					<p v-if="topicGroups.length === 0" class="recommendation-topics__empty">
						{{ t('social', 'No topics match your search.') }}
					</p>
					<section v-for="group in topicGroups" :key="group.root.id" class="recommendation-topics__group">
						<div class="recommendation-topics__group-head">
							<h4>{{ labelOf(group.root) }}</h4>
							<select
								:id="`recommendation-topic-${group.root.id}`"
								v-model="draft[group.root.id]"
								:disabled="saving"
								:aria-label="t('social', 'Preference for {topic}', { topic: labelOf(group.root) })">
								<option value="">
									{{ t('social', 'No preference') }}
								</option>
								<option v-for="mode in MODES" :key="mode.value" :value="mode.value">
									{{ mode.label() }}
								</option>
							</select>
						</div>
						<details v-if="group.children.length > 0" class="recommendation-topics__subtopics" :open="topicSearch.trim() !== ''">
							<summary>{{ n('social', '%n subtopic', '%n subtopics', group.children.length) }}</summary>
							<ul class="recommendation-topics__children">
								<li v-for="topic in group.children" :key="topic.id" class="recommendation-topics__item">
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
						</details>
					</section>
				</div>
				<div v-if="!automaticProfile" class="recommendation-topics__actions">
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
				<p v-if="!automaticProfile" class="recommendation-topics__hint">
					{{ t('social', 'Revision {revision}. New choices and learning updates affect the next For You session; the current scroll order stays stable.', { revision }) }}
				</p>
			</template>
		</template>
		<NcDialog
			:open="confirmingReset"
			:name="t('social', 'Reset topic choices?')"
			:buttons="resetButtons"
			@update:open="confirmingReset = $event">
			<p>{{ t('social', 'This clears only the topic choices saved for For You. Follows, mutes, blocks and the existing hashtag feed are unchanged.') }}</p>
		</NcDialog>
		<NcDialog
			:open="confirmingLearningReset"
			:name="t('social', 'Reset learned signals?')"
			:buttons="learningResetButtons"
			@update:open="confirmingLearningReset = $event">
			<p>{{ t('social', 'This permanently clears the learned topic, author and format profile, impression history and pending event IDs. It keeps your topic choices, follows, mutes and blocks.') }}</p>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getLanguage, translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
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
const interestsUrl = () => generateUrl('apps/social/api/v1/interests')
const resetUrl = () => generateUrl('apps/social/api/v1/recommendations/reset')
const learningUrl = () => generateUrl('apps/social/api/v1/recommendations/learning')
const exportUrl = () => generateUrl('apps/social/api/v1/recommendations/export')

export default {
	name: 'RecommendationTopicsSettings',
	components: { NcButton, NcDialog, NcLoadingIcon, NcTextField },

	props: {
		automaticProfile: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			MODES,
			topics: [],
			revision: 0,
			generation: 0,
			onboardingStep: 0,
			onboardingComplete: false,
			contentBalance: 'balanced',
			draftContentBalance: 'balanced',
			selectedLanguages: [],
			languageInput: '',
			languageError: '',
			learningMode: 'off',
			savedLearningMode: 'off',
			stateTopics: [],
			draft: {},
			topicSearch: '',
			loading: true,
			saving: false,
			confirmingReset: false,
			confirmingLearningReset: false,
		}
	},

	computed: {
		dirty() {
			return this.topics.some((topic) => (this.draft[topic.id] || '') !== (this.saved[topic.id] || ''))
				|| this.draftContentBalance !== this.contentBalance
		},

		wizardStep() {
			const savedStep = Math.max(0, Math.min(5, Number(this.onboardingStep) || 0))
			if (savedStep <= 0) {
				return 0
			}
			if (savedStep <= 2) {
				return 1
			}
			return savedStep === 3 ? 2 : 3
		},

		onboardingSteps() {
			return [t('social', 'Topics'), t('social', 'Languages'), t('social', 'Content balance'), t('social', 'Review')]
		},

		rootTopics() {
			return this.topics.filter((topic) => !topic.parentId && !topic.policyTags?.includes('sensitive-topic'))
		},

		topicGroups() {
			const query = this.topicSearch.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
			return this.topics.filter((topic) => !topic.parentId).map((root) => {
				const matches = (topic) => {
					const text = [this.labelOf(topic), topic.id, ...(topic.aliases || [])]
						.join(' ')
						.normalize('NFD')
						.replace(/[\u0300-\u036f]/g, '')
						.toLowerCase()
					return text.includes(query)
				}
				const rootMatches = query === '' || matches(root)
				const children = this.topics.filter((topic) => topic.parentId === root.id && !topic.policyTags?.includes('sensitive-topic'))
					.filter((topic) => rootMatches || matches(topic))
				return rootMatches || children.length > 0 ? [{ root, children: rootMatches || query === '' ? children : children.filter(matches) }] : []
			}).flat()
		},

		languageOptions() {
			const commonCodes = ['ar', 'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'hu', 'id', 'it', 'ja', 'ko', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sv', 'th', 'tr', 'uk', 'vi', 'zh']
			const codes = [...new Set([...commonCodes, ...this.selectedLanguages])]
			return codes.map((code) => ({ code, label: this.languageLabel(code) }))
		},

		canAddLanguage() {
			return this.languageInput !== ''
				&& /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/.test(this.languageInput)
				&& this.selectedLanguages.length < 20
				&& !this.selectedLanguages.some((language) => language.toLowerCase() === this.languageInput.toLowerCase())
		},

		balanceOptions() {
			return [
				{ value: 'balanced', label: t('social', 'Balanced mix'), description: t('social', 'Keep the current balance; a well-matched new author may use up to one slot in ten.') },
				{ value: 'following', label: t('social', 'More from people I follow'), description: t('social', 'Show fewer exploration picks; the current limit is up to one slot in twenty.') },
				{ value: 'discovery', label: t('social', 'More new voices'), description: t('social', 'Show more qualified exploration picks; the current limit is up to one slot in five.') },
			]
		},

		selectedTopicLabels() {
			return this.topics.filter((topic) => ['INTERESTED', 'PRIORITISE'].includes(this.draft[topic.id])).map((topic) => this.labelOf(topic)).join(', ')
		},

		selectedLanguageLabels() {
			if (this.selectedLanguages.length === 0) {
				return t('social', 'All languages')
			}
			return this.selectedLanguages.map((code) => `${this.languageLabel(code)} (${code})`).join(', ')
		},

		selectedBalanceLabel() {
			return this.balanceOptions.find((choice) => choice.value === this.draftContentBalance)?.label || this.balanceOptions[0].label
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

		learningResetButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.confirmingLearningReset = false } },
				{ label: t('social', 'Reset learned signals'), variant: /** @type {'error'} */ ('error'), disabled: this.saving, callback: () => this.resetLearning() },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		labelOf(topic) {
			const language = (getLanguage() || 'en').toLowerCase().slice(0, 2)
			return topic.labels?.[language] || topic.labels?.en || topic.id
		},

		languageLabel(code) {
			try {
				return new Intl.DisplayNames([getLanguage() || 'en'], { type: 'language' }).of(code) || code
			} catch {
				return code
			}
		},

		validateLanguageInput() {
			if (this.languageInput === '') {
				this.languageError = ''
			} else if (!/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/.test(this.languageInput)) {
				this.languageError = t('social', 'Enter a language code such as fr or pt-BR.')
			} else if (this.selectedLanguages.some((language) => language.toLowerCase() === this.languageInput.toLowerCase())) {
				this.languageError = t('social', 'That language is already selected.')
			} else if (this.selectedLanguages.length >= 20) {
				this.languageError = t('social', 'You can select at most 20 languages.')
			} else {
				this.languageError = ''
			}
		},

		addLanguage() {
			this.validateLanguageInput()
			if (!this.canAddLanguage) {
				return
			}
			this.selectedLanguages = [...this.selectedLanguages, this.languageInput]
			this.languageInput = ''
			this.languageError = ''
		},

		async load() {
			this.loading = true
			try {
				const [catalogResponse, preferencesResponse, interestsResponse] = await Promise.all([axios.get(catalogUrl()), axios.get(preferencesUrl()), axios.get(interestsUrl())])
				this.topics = catalogResponse.data?.topics ?? []
				this.applyState(preferencesResponse.data)
				this.selectedLanguages = Array.isArray(interestsResponse.data?.settings?.languages) ? interestsResponse.data.settings.languages : []
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not load your For You profile')))
			} finally {
				this.loading = false
			}
		},

		applyState(state = {}) {
			this.revision = Number(state.revision) || 0
			this.generation = Number(state.generation) || 0
			this.onboardingStep = Number(state.onboarding_step) || 0
			this.onboardingComplete = state.onboarding_complete === true
			this.contentBalance = ['balanced', 'following', 'discovery'].includes(state.content_balance) ? state.content_balance : 'balanced'
			this.draftContentBalance = this.contentBalance
			this.learningMode = ['off', 'on', 'paused'].includes(state.learning_mode) ? state.learning_mode : 'off'
			this.savedLearningMode = this.learningMode
			this.stateTopics = Array.isArray(state.topics) ? state.topics : []
			this.draft = Object.fromEntries(this.stateTopics.map((entry) => [entry.topic_id, entry.mode]))
		},

		inheritedHide(topicId) {
			return this.topics.some((topic) => topic.id === topicId && this.draft[topic.parentId] === 'HIDE')
		},

		topicSelected(topicId) {
			return ['INTERESTED', 'PRIORITISE'].includes(this.draft[topicId])
		},

		/**
		 * @param {string} topicId - Topic being changed.
		 * @param {Event} event - Checkbox change event.
		 */
		toggleTopic(topicId, event) {
			const selected = event.currentTarget instanceof HTMLInputElement && event.currentTarget.checked
			this.draft = { ...this.draft, [topicId]: selected ? 'INTERESTED' : '' }
		},

		preferencePayload(onboardingStep, onboardingComplete = false) {
			const storedStep = onboardingComplete
				? 5
				: [0, 2, 3, 4][Math.max(0, Math.min(3, Number(onboardingStep) || 0))]
			const topics = Object.entries(this.draft)
				.filter(([, mode]) => mode)
				.map(([topicId, mode]) => ({ topic_id: topicId, mode }))
			return {
				if_revision: this.revision,
				topics,
				content_balance: this.draftContentBalance,
				onboarding_step: storedStep,
				onboarding_complete: onboardingComplete,
			}
		},

		async persistWizardStep(step, complete = false) {
			this.saving = true
			try {
				const { data } = await axios.put(preferencesUrl(), this.preferencePayload(step, complete))
				this.applyState(data)
				return true
			} catch (error) {
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyState(current)
				}
				showError(errorOf(error, t('social', 'Could not save your For You setup')))
				return false
			} finally {
				this.saving = false
			}
		},

		async advanceWizard() {
			if (this.wizardStep === 1) {
				this.saving = true
				try {
					await axios.put(interestsUrl() + '/settings', { languages: this.selectedLanguages })
				} catch (error) {
					showError(errorOf(error, t('social', 'Could not save your language choices')))
					return
				} finally {
					this.saving = false
				}
			}
			if (await this.persistWizardStep(this.wizardStep + 1)) {
				showSuccess(t('social', 'Setup progress saved'))
			}
		},

		async backWizard() {
			await this.persistWizardStep(this.wizardStep - 1)
		},

		async completeWizard() {
			if (await this.persistWizardStep(5, true)) {
				showSuccess(t('social', 'Your For You feed is ready'))
			}
		},

		async save() {
			this.saving = true
			try {
				const { data } = await axios.put(preferencesUrl(), this.preferencePayload(this.onboardingComplete ? 5 : this.onboardingStep, this.onboardingComplete))
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
			this.confirmingLearningReset = false
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
	&__onboarding,
	&__balance {
		margin-block: 1rem 1.5rem;
		padding: 1rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__steps {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem;
		padding: 0;
		margin: 1rem 0;
		list-style: none;

		li {
			padding: 0.35rem 0.65rem;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-pill);
			color: var(--color-text-maxcontrast);
		}

		li[aria-current='step'] {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-text);
			font-weight: 600;
		}
	}

	&__choices {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
		gap: 0.5rem;
		margin-block: 1rem;

		&--column {
			grid-template-columns: 1fr;
		}
	}

	&__language-entry {
		margin-block: 1rem;
		max-width: 36rem;
	}

	&__language-entry-controls {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 0.5rem;

		input {
			flex: 1 1 14rem;
			min-width: 0;
		}
	}

	&__error {
		color: var(--color-error);
	}

	&__choice {
		display: flex;
		align-items: flex-start;
		gap: 0.65rem;
		padding: 0.75rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		cursor: pointer;

		&:has(input:checked) {
			border-color: var(--color-primary-element);
		}

		input {
			margin-block-start: 0.2rem;
		}

		small {
			display: block;
			margin-block-start: 0.25rem;
			color: var(--color-text-maxcontrast);
		}
	}

	&__review {
		margin-block: 1rem;

		div {
			display: grid;
			grid-template-columns: minmax(8rem, 0.35fr) 1fr;
			gap: 0.75rem;
			padding-block: 0.5rem;
			border-bottom: 1px solid var(--color-border);
		}

		dd {
			margin: 0;
		}
	}

	&__learning {
		margin-block: 1rem 1.5rem;
		padding: 1rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__learning-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 0.75rem;
		align-items: center;

		select {
			min-width: 10rem;
		}
	}
	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 70ch;
	}

	&__browser {
		margin-block: 1.25rem;
	}

	&__search {
		max-width: 38rem;
	}

	&__group {
		margin-block: 0.75rem;
		padding: 0.75rem 1rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
	}

	&__group-head {
		display: grid;
		grid-template-columns: minmax(10rem, 1fr) minmax(13rem, 20rem);
		align-items: center;
		gap: 0.75rem;

		h4 {
			margin: 0;
		}
	}

	&__children {
		list-style: none;
		padding: 0;
		margin: 0.25rem 0 0 1rem;
		border-inline-start: 2px solid var(--color-border);
	}

	&__subtopics {
		margin-block-start: 0.5rem;

		summary {
			min-height: 40px;
			display: flex;
			align-items: center;
			color: var(--color-text-maxcontrast);
			cursor: pointer;
		}
	}

	&__item {
		display: grid;
		grid-template-columns: minmax(10rem, 1fr) minmax(13rem, 20rem);
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

	&__group-head select,
	&__item select,
	&__balance select {
		min-height: 44px;
		padding-inline: 0.75rem 2rem;
		border: 1px solid var(--color-border-dark, var(--color-border));
		border-radius: var(--border-radius);
		background-color: var(--color-main-background);
		color: var(--color-main-text);
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
		&__group-head {
			grid-template-columns: 1fr;
		}

		&__item {
			grid-template-columns: 1fr;
		}

		&__inheritance {
			grid-column: 1;
		}
	}
}
</style>

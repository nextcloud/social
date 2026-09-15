<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="portfolio-settings">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcCheckboxRadioSwitch v-model="form.active" type="switch">
				{{ t('social', 'Publish my portfolio') }}
			</NcCheckboxRadioSwitch>
			<p class="portfolio-settings__hint">
				{{ t('social', 'Until this is on, the page is a draft only you can see. Once it is on, anybody with the link can read it without signing in — which is the point of it.') }}
			</p>

			<p v-if="form.active && publicUrl" class="portfolio-settings__url">
				<a :href="publicUrl" target="_blank" rel="noopener noreferrer">{{ publicUrl }}</a>
			</p>

			<NcTextField
				v-model="form.title"
				class="portfolio-settings__field"
				:label="t('social', 'Title')"
				:placeholder="displayName"
				maxlength="128" />

			<NcTextArea
				v-model="form.intro"
				class="portfolio-settings__field"
				:label="t('social', 'A sentence about the work')"
				maxlength="500"
				rows="3" />

			<fieldset class="portfolio-settings__group">
				<legend>{{ t('social', 'How the pictures are laid out') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.layout"
					value="grid"
					name="layout"
					type="radio">
					{{ t('social', 'A grid of squares') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.layout"
					value="rows"
					name="layout"
					type="radio">
					{{ t('social', 'One at a time, at its own shape') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="portfolio-settings__group">
				<legend>{{ t('social', 'Which pictures') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.source"
					value="recent"
					name="source"
					type="radio">
					{{ t('social', 'My most recent public photos') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.source"
					value="collection"
					name="source"
					type="radio">
					{{ t('social', 'One of my collections') }}
				</NcCheckboxRadioSwitch>
				<NcSelect
					v-if="form.source === 'collection'"
					v-model="chosenCollection"
					class="portfolio-settings__select"
					:options="collections"
					label="title"
					:placeholder="t('social', 'Which collection')" />
			</fieldset>

			<fieldset class="portfolio-settings__group">
				<legend>{{ t('social', 'What to show beside each picture') }}</legend>
				<NcCheckboxRadioSwitch v-model="form.showCaptions" type="switch">
					{{ t('social', 'The caption') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.showPlaces" type="switch">
					{{ t('social', 'Where it was taken') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.showDates" type="switch">
					{{ t('social', 'The year') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.showAvatar" type="switch">
					{{ t('social', 'My picture at the top') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<p v-if="message" class="portfolio-settings__message" role="status">
				{{ message }}
			</p>

			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'

/**
 * The editor for somebody's page of work.
 *
 * Everything on it is one form saved in one request, because the page it
 * describes is one page: a card that saved the layout and refused the title
 * would leave somebody guessing which of their changes took.
 */
export default {
	name: 'PortfolioSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			form: {
				active: false,
				title: '',
				intro: '',
				layout: 'grid',
				source: 'recent',
				collectionId: 0,
				showCaptions: true,
				showPlaces: true,
				showDates: false,
				showAvatar: true,
			},

			collections: [],
			chosenCollection: null,
			loading: true,
			saving: false,
			message: '',
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {string} */
		displayName() {
			return this.accountStore.currentAccount?.display_name ?? ''
		},

		/** @return {string} the address to hand somebody */
		publicUrl() {
			const acct = this.accountStore.currentAccount?.acct
			if (!acct) {
				return ''
			}

			return window.location.origin + generateUrl('apps/social/@{acct}/portfolio', { acct })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const [portfolio, collections] = await Promise.all([
					axios.get(generateUrl('apps/social/api/v1.1/portfolio')),
					axios.get(generateUrl('apps/social/api/v1.1/collections/self')).catch(() => ({ data: [] })),
				])

				const it = portfolio.data ?? {}
				this.form = {
					active: it.active === true,
					title: it.title ?? '',
					intro: it.intro ?? '',
					layout: it.layout ?? 'grid',
					source: it.source ?? 'recent',
					collectionId: parseInt(it.collection_id ?? 0, 10) || 0,
					showCaptions: it.show_captions !== false,
					showPlaces: it.show_places !== false,
					showDates: it.show_dates === true,
					showAvatar: it.show_avatar !== false,
				}

				this.collections = (collections.data ?? []).map((one) => ({
					id: parseInt(one.id, 10),
					title: one.title,
				}))
				this.chosenCollection = this.collections.find((one) => one.id === this.form.collectionId) ?? null
			} catch (error) {
				logger.error('could not load the portfolio', { error })
				showError(t('social', 'Could not load your portfolio'))
			} finally {
				this.loading = false
			}
		},

		/** @return {Promise<boolean>} whether it was taken */
		async save() {
			this.saving = true
			this.message = ''
			try {
				await axios.post(generateUrl('apps/social/api/v1.1/portfolio'), {
					active: this.form.active,
					title: this.form.title,
					intro: this.form.intro,
					layout: this.form.layout,
					source: this.form.source,
					collection_id: this.chosenCollection?.id ?? 0,
					show_captions: this.form.showCaptions,
					show_places: this.form.showPlaces,
					show_dates: this.form.showDates,
					show_avatar: this.form.showAvatar,
				})
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'Your portfolio was saved'))

				return true
			} catch (error) {
				logger.error('could not save the portfolio', { error })
				this.message = error.response?.data?.error ?? t('social', 'Could not save it')
				showError(this.message)

				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.portfolio-settings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.portfolio-settings__hint,
.portfolio-settings__message {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.portfolio-settings__url {
	margin: 0;
	word-break: break-all;
}

.portfolio-settings__field {
	max-width: 480px;
}

.portfolio-settings__group {
	border: none;
	padding: 0;
	margin-block: 8px 0;

	legend {
		font-weight: bold;
		padding: 0;
	}
}

.portfolio-settings__select {
	margin-block-start: 4px;
	min-width: 260px;
}
</style>

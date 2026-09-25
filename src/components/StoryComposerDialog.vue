<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Add to your story')"
		:buttons="buttons"
		class="story-composer"
		@update:open="$emit('update:open', $event)">
		<p class="story-composer__hint">
			{{ t('social', 'One picture or video, for the people who follow you, gone after a day.') }}
		</p>

		<!-- opened by the button below, which is the control a keyboard and a
		     screen reader reach; the input stays out of both -->
		<input
			ref="file"
			type="file"
			accept="image/*,video/mp4,video/webm,video/quicktime"
			class="hidden-visually"
			tabindex="-1"
			aria-hidden="true"
			@change="choose">

		<button
			v-if="!file"
			type="button"
			class="story-composer__pick"
			@click="$refs.file.click()">
			<IconImagePlus :size="32" />
			<span>{{ t('social', 'Choose a picture or a video') }}</span>
		</button>

		<div v-else class="story-composer__preview">
			<video
				v-if="isVideo"
				class="story-composer__media"
				:src="previewUrl"
				muted
				playsinline
				controls />
			<img
				v-else
				class="story-composer__media"
				:src="previewUrl"
				alt="">
			<NcButton variant="tertiary" @click="$refs.file.click()">
				{{ t('social', 'Choose another') }}
			</NcButton>
		</div>

		<NcTextField
			v-model="caption"
			class="story-composer__caption"
			:label="t('social', 'Caption')"
			:placeholder="t('social', 'Optional')"
			maxlength="500" />

		<fieldset v-if="!isVideo" class="story-composer__duration">
			<legend>{{ t('social', 'Shown for') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in durations"
				:key="option"
				v-model="duration"
				type="radio"
				name="story-duration"
				:value="String(option)">
				{{ n('social', '%n second', '%n seconds', option) }}
			</NcCheckboxRadioSwitch>
		</fieldset>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useTimelineStore } from '../store/timeline.js'

/** the seconds a picture may be shown for; the server clamps to 3–30 */
export const STORY_DURATIONS = [5, 10, 15]

/**
 * Posts one story: a picture or a video, a caption, and how long a picture
 * stays on screen.
 *
 * The file goes up through the same upload every attachment takes
 * (`/api/v1/media`), so it is stripped of its metadata like any other
 * picture posted here, and the story is then made of that upload. A story
 * is for followers and lasts a day; the dialog says so, since neither is
 * how a post behaves.
 */
export default {
	name: 'StoryComposerDialog',

	components: {
		IconImagePlus,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'posted'],

	data() {
		return {
			/** @type {File|null} */
			file: null,
			previewUrl: '',
			caption: '',
			duration: '5',
			posting: false,
			durations: STORY_DURATIONS,
		}
	},

	computed: {
		...mapStores(useTimelineStore),

		/** @return {boolean} */
		isVideo() {
			return Boolean(this.file && this.file.type.startsWith('video/'))
		},

		buttons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => this.$emit('update:open', false) },
				{
					label: this.posting ? t('social', 'Posting …') : t('social', 'Post'),
					variant: 'primary',
					disabled: !this.file || this.posting,
					callback: () => this.post(),
				},
			]
		},
	},

	beforeUnmount() {
		this.releasePreview()
	},

	methods: {
		t,
		n,

		/**
		 * @param {Event} event the file input's change
		 */
		choose(event) {
			const file = event.target?.files?.[0] ?? null
			// the input keeps its selection, so picking the same file twice in
			// a row would otherwise be ignored the second time
			event.target.value = ''
			if (!file) {
				return
			}

			this.releasePreview()
			this.file = file
			this.previewUrl = URL.createObjectURL(file)
		},

		releasePreview() {
			if (this.previewUrl !== '') {
				URL.revokeObjectURL(this.previewUrl)
				this.previewUrl = ''
			}
		},

		/** @return {Promise<void>} */
		async post() {
			if (!this.file || this.posting) {
				return
			}

			this.posting = true
			try {
				const media = await this.timelineStore.createMedia(this.file)
				if (!media?.id) {
					// the store has already said what went wrong
					return
				}

				const { data } = await axios.post(generateUrl('apps/social/api/v1/stories'), {
					media_id: media.id,
					caption: this.caption.trim(),
					duration: Number(this.duration) || 5,
				})
				showSuccess(t('social', 'Your story is up for a day'))
				this.$emit('posted', data)
				this.$emit('update:open', false)
			} catch (error) {
				logger.error('could not post the story', { error })
				showError(error?.response?.data?.error || t('social', 'Could not post the story'))
			} finally {
				this.posting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.story-composer__hint {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.story-composer__pick {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	justify-content: center;
	width: 100%;
	min-height: 160px;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		color: var(--color-main-text);
	}
}

.story-composer__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
}

.story-composer__media {
	display: block;
	max-width: 100%;
	max-height: 50vh;
	border-radius: var(--border-radius-large, 12px);
	object-fit: contain;
}

.story-composer__caption {
	margin-top: 12px;
}

.story-composer__duration {
	margin-top: 12px;
	padding: 0;
	border: 0;

	legend {
		margin-bottom: 4px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}
</style>

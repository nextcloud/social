<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="preview-item-wrapper">
		<div class="preview-item">
			<!-- a refused attachment has no picture behind it, and the spinner
			     MediaAttachment shows for `null` would never stop -->
			<div class="preview-item__filtered" :style="{ filter: filterCss(preview.filter) }">
				<MediaAttachment v-if="!preview.failed" :attachment="preview.data" />
			</div>

			<div class="preview-item__actions">
				<NcButton variant="tertiary-no-background" @click="$emit('delete', randomKey)">
					<template #icon>
						<Close :size="16" fillColor="white" />
					</template>
					<span>{{ t('social', 'Delete') }}</span>
				</NcButton>
			</div>

			<!-- a picture nobody described is a picture some readers never see -->
			<span v-if="!described && !preview.failed" class="preview-item__missing" aria-hidden="true">
				{{ t('social', 'No description') }}
			</span>

			<!-- one attachment out of several can be refused, and the grid is
			     the only place that can say which one -->
			<span v-if="preview.failed" class="preview-item__failed" role="status">
				{{ t('social', 'Could not be attached') }}
			</span>
		</div>

		<!-- Pictures only: there is nothing a filter could do to a video or an
		     audio file, and offering one would be a button that does nothing.
		     Not until the upload has landed either: choosing a filter replaces
		     the uploaded copy, and starting that while the first upload is
		     still in flight is a race with no winner. -->
		<FilterPicker
			v-if="!preview.failed && isPicture && preview.data"
			class="preview-item__filters"
			:modelValue="preview.filter || 'none'"
			:preview="previewUrl"
			@update:modelValue="$emit('filter', { key: randomKey, filter: $event })" />

		<label v-if="!preview.failed" class="preview-item__label" :for="fieldId">
			{{ t('social', 'Describe this for people who cannot see it') }}
		</label>
		<textarea
			v-if="!preview.failed"
			:id="fieldId"
			class="preview-item__description"
			rows="2"
			maxlength="1500"
			:value="preview.description || ''"
			:placeholder="t('social', 'A cat asleep on a keyboard')"
			@input="$emit('describe', { key: randomKey, description: $event.target.value })"
			@change="$emit('commitDescription', { key: randomKey, description: $event.target.value })" />
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'
import FilterPicker from './FilterPicker.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { filterCss } from '../../utils/imageFilters.js'
import { translate } from '@nextcloud/l10n'
import MediaAttachment from '../MediaAttachment.vue'

export default {
	name: 'PreviewGridItem',
	components: {
		Close,
		FilterPicker,
		NcButton,
		MediaAttachment,
	},

	props: {
		/** @type {import('vue').PropType<import('./Composer.vue').LocalAttachment>} */
		preview: {
			type: Object,
			required: true,
		},

		randomKey: {
			type: String,
			required: true,
		},
	},

	emits: ['delete', 'describe', 'commitDescription'],

	computed: {
		described() {
			return (this.preview.description || '').trim() !== ''
		},

		/** Unique per attachment, so the label points at its own field. */
		fieldId() {
			return 'composer-alt-' + this.randomKey
		},

		/**
		 * Whether a filter would do anything. Video and audio have no filter
		 * to apply, and an animated picture would come back as its first frame.
		 *
		 * @return {boolean}
		 */
		isPicture() {
			const type = this.preview?.file?.type || this.preview?.data?.type || ''

			return type.startsWith('image/')
				? !['image/gif', 'image/webp'].includes(type)
				: type === 'image'
		},

		/** @return {string} the object URL the swatches draw, which is the key */
		previewUrl() {
			return this.randomKey
		},
	},

	methods: {
		t: translate,
		filterCss,
	},
}
</script>

<style scoped lang="scss">
.preview-item-wrapper {
	flex: 1 1 0;
	min-width: 40%;
	margin: 5px;
}

.preview-item__missing {
	position: absolute;
	inset-inline-start: 8px;
	inset-block-end: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	font-weight: 600;
}

.preview-item__failed {
	position: absolute;
	inset-inline-start: 8px;
	inset-block-end: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-error);
	color: var(--color-primary-element-text, white);
	font-size: 12px;
	font-weight: 600;
}

.preview-item__label {
	display: block;
	margin: 6px 2px 2px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.preview-item__description {
	width: 100%;
	min-height: 44px;
	resize: vertical;
	box-sizing: border-box;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	padding: 6px 8px;

	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.preview-item {
	border-radius: var(--border-radius-large);
	background: var(--color-background-darker);
	background-position: 50%;
	background-size: cover;
	background-repeat: no-repeat;
	height: 140px;
	width: 100%;
	overflow: hidden;
	position: relative;

	.button-vue--tertiary-no-background {
		color: white !important;
	}

	&__actions {
		position: absolute;
		top: 0;
		width: 100%;
		background: linear-gradient(180deg,rgba(0,0,0,.8),rgba(0,0,0,.35) 80%,transparent);
		display: flex;
		align-items: flex-start;
		justify-content: space-between;

		.button-vue__text {
			color: white !important;
		}
	}

	.description-warning {
		position: absolute;
		z-index: 2;
		bottom: 0;
		inset-inline: 0;
		box-sizing: border-box;
		background: linear-gradient(0deg,rgba(0,0,0,.8),rgba(0,0,0,.35) 80%,transparent);
		color: white;
		padding: 10px;
	}
}

.modal__content {
	padding: 20px;
}

textarea {
	width: 100%;
	height: 100px;
	margin-bottom: 20px;
}
</style>

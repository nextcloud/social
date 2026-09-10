<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="upload-form">
		<!-- v-if="false" meant the bar never appeared at all, and the progress
		     it would have shown was the hard-coded 0.4 the composer passed -->
		<div v-if="uploading" class="upload-progress">
			<div class="upload-progress__icon">
				<FileUpload :size="32" />
			</div>

			<div class="upload-progress__message">
				{{ t('social', 'Uploading...') }}

				<div class="upload-progress__backdrop">
					<div class="upload-progress__tracker" :style="`width: ${uploadProgress * 100}%`" />
				</div>
			</div>
		</div>
		<div class="preview-grid">
			<PreviewGridItem v-for="(item, randomKey) in miniatures"
				:key="randomKey"
				:preview="item"
				:random-key="randomKey"
				@delete="deletePreview"
				@describe="$emit('describe', $event)" />
		</div>
	</div>
</template>

<script>
import PreviewGridItem from './PreviewGridItem.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'PreviewGrid',
	components: {
		PreviewGridItem,
		FileUpload,
	},
	emits: ['deleted', 'describe'],
	props: {
		uploadProgress: {
			type: Number,
			required: true,
		},
		uploading: {
			type: Boolean,
			required: true,
		},
		/** @type {import('vue').PropType<Object<string, import('./Composer.vue').LocalAttachment>>} */
		miniatures: {
			type: Object,
			required: true,
		},
	},
	methods: {
		deletePreview(randomKey) {
			this.$emit('deleted', randomKey)
		},

		t: translate,
	},
}
</script>

<style scoped lang="scss">
.upload-progress {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 8px;

	&__message {
		flex-grow: 1;
		font-size: 13px;
		color: var(--color-text-lighter);
	}

	&__backdrop {
		margin-top: 4px;
		height: 4px;
		border-radius: 2px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__tracker {
		height: 100%;
		background: var(--color-primary-element);
		transition: width .2s ease;
	}
}

@media (prefers-reduced-motion: reduce) {
	.upload-progress__tracker {
		transition: none;
	}
}

.preview-grid {
	display: flex;
	flex-wrap: wrap;
	flex-direction: row;
	margin-left: -5px;
	margin-right: -5px;
}
</style>

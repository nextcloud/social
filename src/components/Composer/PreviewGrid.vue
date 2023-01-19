<!--
SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="upload-form">
		<div v-if="false" class="upload-progress">
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
				@delete="deletePreview" />
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
}

.preview-grid {
	display: flex;
	flex-wrap: wrap;
	flex-direction: row;
	margin-left: -5px;
	margin-right: -5px;
}
</style>

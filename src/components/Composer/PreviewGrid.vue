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
			<PreviewGridItem v-for="(item, index) in miniatures"
				:key="index"
				:preview="item"
				:index="index"
				@delete="deletePreview" />
		</div>
	</div>
</template>

<script>
import PreviewGridItem from './PreviewGridItem.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'

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
		miniatures: {
			type: Array,
			required: true,
		},
	},
	methods: {
		deletePreview(index) {
			$emit('deleted', index)
		},
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

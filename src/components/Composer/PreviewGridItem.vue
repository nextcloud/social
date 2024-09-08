<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="preview-item-wrapper">
		<div class="preview-item">
			<MediaAttachment :attachment="preview.data" />

			<div class="preview-item__actions">
				<NcButton type="tertiary-no-background" @click="$emit('delete', randomKey)">
					<template #icon>
						<Close :size="16" fill-color="white" />
					</template>
					<span>{{ t('social', 'Delete') }}</span>
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { translate } from '@nextcloud/l10n'
import MediaAttachment from '../MediaAttachment.vue'

export default {
	name: 'PreviewGridItem',
	components: {
		Close,
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
	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.preview-item-wrapper {
	flex: 1 1 0;
	min-width: 40%;
	margin: 5px;
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

	.button-vue--vue-tertiary-no-background {
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
		left: 0;
		right: 0;
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

<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="attachment" @click="$emit('click')">
		<video v-if="attachment !== null && attachment.type === 'video'"
			class="attachment__preview"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			controls
			preload="metadata"
			@click.stop
			@loadedmetadata="previewLoaded = true" />
		<audio v-else-if="attachment !== null && attachment.type === 'audio'"
			class="attachment__audio"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			controls
			preload="metadata"
			@click.stop
			@loadedmetadata="previewLoaded = true" />
		<template v-else>
			<canvas ref="canvas"
				class="attachment__blurhash"
				:class="{ 'attachment__blurhash--hidden': previewLoaded }" />
			<img v-if="attachment !== null"
				class="attachment__preview attachment__preview--fading"
				:class="{ 'attachment__preview--shown': previewLoaded }"
				:src="attachment.preview_url"
				:alt="attachment.description || ''"
				@load="previewLoaded = true">
		</template>
		<NcLoadingIcon v-if="attachment === null || (!previewLoaded && !isAv)" :size="40" />
	</div>
</template>

<script>
import { decode } from 'blurhash'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'MediaAttachment',
	components: {
		NcLoadingIcon,
	},
	emits: ['click'],
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon').MediaAttachment>} */
		attachment: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			previewLoaded: false,
		}
	},
	computed: {
		/** @return {boolean} */
		isAv() {
			return this.attachment?.type === 'video' || this.attachment?.type === 'audio'
		},
	},
	watch: {
		attachment() {
			this.drawBlurhash()
		},
	},
	mounted() {
		this.drawBlurhash()
	},
	methods: {
		drawBlurhash() {
			if (this.isAv || this.attachment?.meta?.small?.width === undefined) {
				return
			}

			if (!this.$refs.canvas) {
				return
			}

			const ctx = this.$refs.canvas.getContext('2d')
			const imageData = ctx.createImageData(this.attachment.meta.small.width, this.attachment.meta.small.height)
			const pixels = decode(this.attachment.blurhash, this.attachment.meta.small.width, this.attachment.meta.small.height)
			imageData.data.set(pixels)
			ctx.putImageData(imageData, 0, 0)
		},
	},
}
</script>

<style scoped lang="scss">
.attachment {
	position: relative;
	height: 100%;
	width: 100%;

	&__blurhash {
		position: absolute;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 1;
		transition: opacity .4s ease;

		&--hidden {
			opacity: 0;
		}
	}

	&__preview {
		position: absolute;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 2;

		/* the image resolves out of its own blur rather than replacing it */
		&--fading {
			opacity: 0;
			transform: scale(1.02);
			transition: opacity .4s ease, transform .4s ease;
		}

		&--shown {
			opacity: 1;
			transform: scale(1);
		}
	}

	.loading-icon {
		position: absolute;
		top: calc(50% - 20px);
		left: calc(50% - 20px);
		z-index: 3;
	}
}

@media (prefers-reduced-motion: reduce) {
	.attachment__blurhash,
	.attachment__preview--fading {
		transition: none;
	}

	.attachment__preview--fading {
		transform: none;
	}
}
</style>

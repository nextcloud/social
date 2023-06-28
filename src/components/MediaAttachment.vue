<template>
	<div class="attachment" @click="$emit('click')">
		<canvas v-if="!previewLoaded" ref="canvas" class="attachment__blurhash" />
		<img v-if="attachment !== null"
			class="attachment__preview"
			:src="attachment.preview_url"
			@load="previewLoaded = true">
		<NcLoadingIcon v-if="attachment === null || !previewLoaded" :size="40" />
	</div>
</template>

<script>
import { decode } from 'blurhash'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'MediaAttachment',
	components: {
		NcLoadingIcon,
	},
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
			if (this.attachment?.meta.small.width === undefined) {
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

	&__blurhash, &__preview {
		position: absolute;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
	}

	.loading-icon {
		position: absolute;
		top: calc(50% - 20px);
		left: calc(50% - 20px);
	}
}
</style>

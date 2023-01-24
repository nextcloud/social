<template>
	<div class="post-attachments">
		<div v-for="(item, index) in attachments"
			:key="index"
			class="post-attachment"
			@click="showModal(index)">
			<img v-if="item.mimeType.startsWith('image/')" :src="imageUrl(item)">
			<div v-else>
				{{ item }}
			</div>
		</div>
		<NcModal v-if="modal"
			:has-previous="current > 0"
			:has-next="current < (attachments.length - 1)"
			size="full"
			@close="closeModal"
			@previous="showPrevious"
			@next="showNext">
			<div class="modal__content">
				<canvas ref="modalCanvas" />
			</div>
		</NcModal>
	</div>
</template>

<script>
import serverData from '../mixins/serverData.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PostAttachment',
	components: {
		NcModal,
	},
	mixins: [
		serverData,
	],
	props: {
		attachments: {
			type: Array,
			default: Array,
		},
	},
	data() {
		return {
			modal: false,
			current: '',
		}
	},
	methods: {
		/**
		 * @function imageUrl
		 * @description Returns the URL where to get a resized version of the attachement
		 * @param {object} item - The attachment
		 * @return {string} The URL
		 */
		imageUrl(item) {
			if (this.serverData.public) {
				return generateUrl('/apps/social/document/public/resized?id=' + item.id)
			} else {
				return generateUrl('/apps/social/document/get/resized?id=' + item.id)
			}
		},
		/**
		 * @function displayImage
		 * @description Displays the currently selected attachment's image
		 */
		displayImage() {
			const canvas = this.$refs.modalCanvas
			const ctx = canvas.getContext('2d')
			const img = new Image()
			img.onload = function() {
				let width = img.width
				let height = img.height
				if (width > window.innerWidth) {
					height = height * (window.innerWidth / width)
					width = window.innerWidth
				}
				if (height > window.innerHeight) {
					width = width * (window.innerHeight / height)
					height = window.innerHeight
				}
				canvas.width = width
				canvas.height = height
				ctx.drawImage(img, 0, 0, width, height)
			}
			img.src = generateUrl('/apps/social/document/get?id=' + this.attachments[this.current].id)
		},
		showModal(idx) {
			this.current = idx
			this.displayImage()
			this.modal = true
		},
		closeModal() {
			this.modal = false
		},
		showPrevious() {
			this.current--
			this.displayImage()
		},
		showNext() {
			this.current++
			this.displayImage()
		},
	},
}
</script>
<style lang="scss" scoped>
.post-attachments {
	margin-top: 12px;
	width: 100%;
	display: flex;
	gap: 12px;
	overflow-x: scroll;

	.post-attachment {
		height: 100px;
		object-fit: cover;
		border-radius: var(--border-radius-large);
		overflow: hidden;
		flex-shrink: 0;

		> * {
			cursor: pointer;
		}

		img {
			width: 100%;
			height: 100%;
		}
	}
}
</style>

<template>
	<masonry>
		<div v-for="(item, index) in attachments" :key="index">
			<img :src="imageUrl(item)" @click="showModal(index)">
		</div>
		<modal v-show="modal" :has-previous="current > 0" :has-next="current < (attachments.length - 1)"
			size="full" @close="closeModal" @previous="showPrevious"
			@next="showNext">
			<div class="modal__content">
				<canvas ref="modalCanvas" />
			</div>
		</modal>
	</masonry>
</template>

<script>

import serverData from '../mixins/serverData'
import Modal from '@nextcloud/vue/dist/Components/Modal'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PostAttachment',
	components: {
		Modal
	},
	mixins: [
		serverData
	],
	props: {
		attachments: {
			type: Array,
			default: Array
		}
	},
	data() {
		return {
			modal: false,
			current: ''
		}
	},
	methods: {
		/**
		 * @function imageUrl
		 * @description Returns the URL where to get a resized version of the attachement
		 * @param {object} item - The attachment
		 * @returns {string} The URL
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
			var canvas = this.$refs.modalCanvas
			var ctx = canvas.getContext('2d')
			var img = new Image()
			img.onload = function() {
				var width = img.width
				var height = img.height
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
		}
	}
}
</script>

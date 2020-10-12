<template>
	<masonry>
		<div v-for="(item, index) in attachments" :key="index">
			<img :src="generateUrl('/apps/social/document/get/resized?id=' + item.id)" @click="showModal(index)">
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

import Modal from '@nextcloud/vue/dist/Components/Modal'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PostAttachment',
	components: {
		Modal
	},
	mixins: [],
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
		displayResizedImage() {
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
			this.displayResizedImage()
			this.modal = true
		},
		closeModal() {
			this.modal = false
		},
		showPrevious() {
			this.current--
			this.displayResizedImage()
		},
		showNext() {
			this.current++
			this.displayResizedImage()
		}
	}
}
</script>

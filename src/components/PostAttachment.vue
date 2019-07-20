<template>
	<masonry>
		<div v-for="(item, index) in attachments" :key="index">
			<img :src="OC.generateUrl('/apps/social/document/get/resized?id=' + item.id)" @click="showModal(index)">
		</div>
		<modal v-show="modal" :has-previous="current > 0" :has-next="current < (attachments.length - 1)"
			size="full" @close="closeModal" @previous="showPrevious"
			@next="showNext">
			<div class="modal__content">
				<img ref="modalImg" src="">
			</div>
		</modal>
	</masonry>
</template>

<script>

import Modal from 'nextcloud-vue/dist/Components/Modal'

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
		showModal(idx) {
			this.current = idx
			this.$refs.modalImg.src = OC.generateUrl('/apps/social/document/get?id=' + this.attachments[this.current].id)
			this.modal = true
		},
		closeModal() {
			this.modal = false
		},
		showPrevious() {
			this.current--
			this.$refs.modalImg.src = OC.generateUrl('/apps/social/document/get?id=' + this.attachments[this.current].id)
		},
		showNext() {
			this.current++
			this.$refs.modalImg.src = OC.generateUrl('/apps/social/document/get?id=' + this.attachments[this.current].id)
		}
	}
}
</script>

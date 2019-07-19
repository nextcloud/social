<template>
	<masonry>
		<div v-for="(item,idx) in attachments" :key="idx">
			<img :src="OC.generateUrl('/apps/social/document/get/resized?id=' + item.id)" @click="showModal"/>
			<modal v-show="modal" @close="closeModal" size="full">
				<div class="modal__content">
					<img :src="OC.generateUrl('/apps/social/document/get?id=' + item.id)"/>
				</div>
			</modal>
		</div>
	</masonry>
</template>

<script>

import Modal from 'nextcloud-vue/dist/Components/Modal';

export default {
	name: 'PostAttachment',
	components: {
		Modal,
	},
	mixins: [],
	props: {
		attachments: Array,
	},
	data() {
		return {
			modal: false
		}
	},
	methods: {
		showModal() {
			this.modal = true
		},
		closeModal() {
			this.modal = false
		}
	}
}
</script>

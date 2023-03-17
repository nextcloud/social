<template>
	<div class="post-attachments">
		<div class="attachments-container">
			<div v-for="(item, index) in attachementsSlice"
				:key="index"
				class="attachment"
				@click="showModal(index)">
				<MediaAttachment :attachment="item" />
			</div>
			<div v-if="attachments.length > 4" class="attachment more-attachments" @click="showModal(3)">
				+
			</div>
		</div>
		<NcModal v-if="modal"
			:has-previous="current > 0"
			:has-next="current < (attachments.length - 1)"
			size="full"
			@close="closeModal"
			@previous="current--"
			@next="current++">
			<div class="attachment__viewer">
				<img :src="attachments[current].url" :alt="attachments[current].description">
			</div>
		</NcModal>
	</div>
</template>

<script>
import serverData from '../mixins/serverData.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import MediaAttachment from './MediaAttachment.vue'

export default {
	name: 'PostAttachment',
	components: {
		NcModal,
		MediaAttachment,
	},
	mixins: [
		serverData,
	],
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment[]>} */
		attachments: {
			type: Array,
			default: Array,
		},
	},
	data() {
		return {
			modal: false,
			current: 0,
		}
	},
	computed: {
		/** @return {import('../types/Mastodon.js').MediaAttachment[]} */
		attachementsSlice() {
			if (this.attachments.length <= 4) {
				return this.attachments
			} else {
				return this.attachments.slice(0, 3)
			}
		},
	},
	methods: {
		showModal(index) {
			this.current = index
			this.modal = true
		},
		closeModal() {
			this.modal = false
		},
	},
}
</script>
<style lang="scss" scoped>
.post-attachments {
	.attachments-container {
		display: flex;
		flex-wrap: wrap;
		gap: 2px;
		margin-top: 12px;
		width: 100%;
		border-radius: var(--border-radius-large);
		overflow: hidden;
		background: var(--color-background-dark);

		.attachment {
			flex-grow: 1;
			flex-shrink: 1;
			flex-basis: calc(50% - 2px);
			cursor: pointer;
			height: 20vh;
		}

		.more-attachments {
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 42px;
			line-height: 0px;

			&:hover {
				background: var(--color-background-darker);
			}
		}
	}
}

.attachment__viewer {
	display: flex;
	height: 100%;
	width: 100%;
	align-content: center;
	justify-items: center;
	padding: 10%;
	box-sizing: border-box;

	img {
		height: 100%;
		width: 100%;
		object-fit: contain;
	}
}
</style>

<template>
	<div class="preview-item-wrapper">
		<div class="preview-item" :style="backgroundStyle">
			<div class="preview-item__actions">
				<Button type="tertiary-no-background" @click="deletePreview">
					<template #icon>
						<Close :size="16" fill-color="white" />
					</template>
					<span>{{ t('social', 'Delete') }}</span>
				</Button>
				<Button type="tertiary-no-background" @click="showModal">
					<template #icon>
						<Edit :size="16" fill-color="white" />
					</template>
					<span>{{ t('social', 'Edit') }}</span>
				</Button>
			</div>

			<div v-if="preview.description.length === 0" class="description-warning">
				{{ t('social', 'No description added') }}
			</div>

			<Modal v-if="modal" size="small" @close="closeModal">
				<div class="modal__content">
					<label :for="`image-description-${index}`">
						{{ t('social', 'Describe for the visually impaired') }}
					</label>
					<textarea :id="`image-description-${index}`" v-model="internalDescription" />
					<Button type="primary" @click="closeModal">
						{{ t('social', 'Close') }}
					</Button>
				</div>
			</Modal>
		</div>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close'
import Edit from 'vue-material-design-icons/Pencil'
import Button from '@nextcloud/vue/dist/Components/Button'
import Modal from '@nextcloud/vue/dist/Components/Modal'

export default {
	name: 'PreviewGridItem',
	components: {
		Close,
		Edit,
		Button,
		Modal
	},
	props: {
		preview: {
			type: Object,
			required: true
		},
		index: {
			type: Number,
			required: true
		}
	},
	data() {
		return {
			modal: false,
			internalDescription: ''
		}
	},
	computed: {
		backgroundStyle() {
			return {
				backgroundImage: `url("${this.preview.preview_url}")`
			}
		}
	},
	mounted() {
		this.internalDescription = this.preview.description
	},
	methods: {
		deletePreview() {
			this.$store.dispatch('deleteAttachement', {
				id: this.preview.id
			})
		},
		showModal() {
			this.modal = true
		},
		closeModal() {
			this.modal = false
			this.$store.dispatch('updateAttachement', {
				id: this.preview.id,
				description: this.internalDescription
			})
		}
	}
}
</script>

<style scoped lang="scss">
.preview-item-wrapper {
	flex: 1 1 0;
	min-width: 40%;
	margin: 5px;
}

.preview-item {
	border-radius: 4px;
	background-color: #000;
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

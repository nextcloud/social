<template>
	<div class="preview-item-wrapper">
		<div class="preview-item" :style="backgroundStyle">
			<div class="preview-item__actions">
				<NcButton type="tertiary-no-background" @click="$emit('delete', index)">
					<template #icon>
						<Close :size="16" fillColor="white" />
					</template>
					<span>{{ t('social', 'Delete') }}</span>
				</NcButton>
				<NcButton type="tertiary-no-background" @click="showModal">
					<template #icon>
						<Edit :size="16" fillColor="white" />
					</template>
					<span>{{ t('social', 'Edit') }}</span>
				</NcButton>
			</div>

			<div class="description-warning" v-if="preview.description.length === 0">
				{{ t('social', 'No description added') }}
			</div>

			<NcModal v-if="modal" @close="closeModal" size="small">
				<div class="modal__content">
					<label :for="`image-description-${index}`">
						{{ t('social', 'Describe for the visually impaired') }}
					</label>
					<textarea :id="`image-description-${index}`" v-model="preview.description">
					</textarea>
					<NcButton type="primary" @click="closeModal">{{ t('social', 'Close') }}</NcButton>
				</div>
			</NcModal>
		</div>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'
import Edit from 'vue-material-design-icons/Pencil.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'

export default {
	name: 'PreviewGridItem',
	components: {
		Close,
		Edit,
		NcButton,
		NcModal,
	},
	data() {
		return {
			modal: false,
		}
	},
	methods: {
		showModal() {
			this.modal = true
		},
		closeModal() {
			this.modal = false
		}
	},
	props: {
		preview: {
			type: Object,
			required: true,
		},
		index: {
			type: Number,
			required: true,
		},
	},
	computed: {
		backgroundStyle() {
			return {
				backgroundImage: `url("${this.preview.url}")`,
			}
		},
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

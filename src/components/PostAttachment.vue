<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-attachments">
		<div class="attachments-container">
			<template v-for="(item, index) in attachementsSlice" :key="index">
				<!-- an image is opened by pressing it, so it gets a button.
				     Video and audio carry their own controls: nesting those
				     inside a button is invalid, and they need no viewer to be
				     watchable where they are -->
				<button v-if="isPressable(item)"
					ref="thumbnails"
					type="button"
					class="attachment"
					:aria-label="openLabel(item, index)"
					@click="showModal(index)">
					<MediaAttachment :attachment="item" />
				</button>
				<MediaAttachment v-else ref="thumbnails" :attachment="item" />
			</template>
			<button v-if="attachments.length > 4"
				type="button"
				class="attachment more-attachments"
				:aria-label="n('social', 'Show %n more attachment', 'Show %n more attachments', attachments.length - 4)"
				@click="showModal(3)">
				<span aria-hidden="true">+</span>
			</button>
		</div>
		<NcModal v-if="modal"
			:name="currentLabel"
			:has-previous="current > 0"
			:has-next="current < (attachments.length - 1)"
			size="full"
			@close="closeModal"
			@previous="current--"
			@next="current++">
			<div ref="viewer" class="attachment__viewer">
				<video v-if="attachments[current].type === 'video'"
					:src="attachments[current].url"
					:aria-label="attachments[current].description || ''"
					controls
					autoplay />
				<audio v-else-if="attachments[current].type === 'audio'"
					:src="attachments[current].url"
					:aria-label="attachments[current].description || ''"
					controls />
				<img v-else :src="attachments[current].url" :alt="attachments[current].description">
			</div>
		</NcModal>
	</div>
</template>

<script>
import serverData from '../mixins/serverData.js'
import NcModal from '@nextcloud/vue/components/NcModal'
import MediaAttachment from './MediaAttachment.vue'
import { nameForTransition, withViewTransition } from '../utils/viewTransition.js'

/** one name per document: only one lightbox is ever open */
import { translate, translatePlural } from '@nextcloud/l10n'

const MEDIA_TRANSITION = 'social-media'

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
		/** What the open viewer is showing, so the dialog has a name. */
		currentLabel() {
			const attachment = this.attachments[this.current] ?? null

			return attachment?.description
				? attachment.description
				: translate('social', 'Attachment {number} of {total}', {
					number: this.current + 1, total: this.attachments.length,
				})
		},
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
		t: translate,
		n: translatePlural,
		/**
		 * What opening this attachment will show. The author's own description
		 * when there is one, because that is the only thing that says what the
		 * picture actually is.
		 *
		 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the media
		 * @param {number} index its place in the post
		 * @return {string} the button's accessible name
		 */
		/**
		 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the media
		 * @return {boolean} whether pressing it should open the viewer
		 */
		isPressable(attachment) {
			return attachment?.type !== 'video' && attachment?.type !== 'audio'
		},
		openLabel(attachment, index) {
			return attachment.description
				? translate('social', 'Open attachment: {description}', { description: attachment.description })
				: translate('social', 'Open attachment {number}', { number: index + 1 })
		},
		/**
		 * The tapped thumbnail and the opened viewer share a name for the
		 * length of the transition, so the browser grows one into the other
		 * instead of the picture appearing from nowhere.
		 *
		 * @param {number} index which attachment was tapped
		 */
		async showModal(index) {
			const thumbnail = this.$refs.thumbnails?.[index] ?? null
			const release = nameForTransition(thumbnail, MEDIA_TRANSITION)

			await withViewTransition(async () => {
				this.current = index
				this.modal = true
				await this.$nextTick()
				nameForTransition(this.$refs.viewer ?? null, MEDIA_TRANSITION)
			})

			release()
		},
		async closeModal() {
			const release = nameForTransition(this.$refs.viewer ?? null, MEDIA_TRANSITION)
			const thumbnail = this.$refs.thumbnails?.[this.current] ?? null

			await withViewTransition(async () => {
				this.modal = false
				await this.$nextTick()
				nameForTransition(thumbnail, MEDIA_TRANSITION)
			})

			release()
		},
	},
}
</script>
<style lang="scss" scoped>
.post-attachments {
	.attachments-container {
		display: flex;
		flex-wrap: wrap;
		gap: 3px;
		margin-top: 14px;
		width: 100%;
		border-radius: 12px;
		overflow: hidden;
		background: var(--color-background-dark);

		.attachment {
			flex-grow: 1;
			flex-shrink: 1;
			flex-basis: calc(50% - 3px);
			cursor: pointer;
			height: 22vh;
		}

		.more-attachments {
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 42px;
			line-height: 0px;
			color: var(--color-text-lighter);

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

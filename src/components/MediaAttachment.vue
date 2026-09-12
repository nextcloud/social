<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- not focusable on purpose: an image here is already inside the parent's
	     button, and video and audio carry their own controls. The click is a
	     convenience for a pointer, never the only way to reach anything -->
	<div
		class="attachment"
		role="presentation"
		@click="$emit('click')">
		<video
			v-if="attachment !== null && attachment.type === 'video'"
			class="attachment__preview"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			controls
			preload="metadata"
			@click.stop
			@loadedmetadata="previewLoaded = true" />
		<audio
			v-else-if="attachment !== null && attachment.type === 'audio'"
			class="attachment__audio"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			controls
			preload="metadata"
			@click.stop
			@loadedmetadata="previewLoaded = true" />
		<template v-else>
			<canvas
				ref="canvas"
				class="attachment__blurhash"
				:class="{ 'attachment__blurhash--hidden': previewLoaded }" />
			<img
				v-if="hasPreview && !previewFailed"
				class="attachment__preview attachment__preview--fading"
				:class="{ 'attachment__preview--shown': previewLoaded }"
				:src="attachment.preview_url"
				:alt="attachment.description || ''"
				@load="previewLoaded = true"
				@error="onPreviewError">
			<!-- federated media that has gone away used to spin forever: no
			     @error meant previewLoaded stayed false and the spinner stayed.
			     So did media the server says outright it has no preview for -->
			<span
				v-if="showsPlaceholder"
				class="attachment__failed"
				role="img"
				:aria-label="placeholderLabel">
				<ImageOff :size="32" />
			</span>
		</template>
		<NcLoadingIcon v-if="attachment === null || (!previewLoaded && !showsPlaceholder && !isAv)" :size="40" />
	</div>
</template>

<script>
import { decode } from 'blurhash'
import { translate } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ImageOff from 'vue-material-design-icons/ImageOff.vue'
import logger from '../services/logger.js'

export default {
	name: 'MediaAttachment',
	components: {
		ImageOff,
		NcLoadingIcon,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon').MediaAttachment>} */
		attachment: {
			type: Object,
			default: null,
		},
	},

	emits: ['click'],

	data() {
		return {
			previewLoaded: false,
			previewFailed: false,
		}
	},

	computed: {
		/** @return {boolean} */
		isAv() {
			return this.attachment?.type === 'video' || this.attachment?.type === 'audio'
		},

		/**
		 * Whether there is a preview to wait for at all. The server sends
		 * `preview_url: null` when it has none, and Vue drops a null `src`:
		 * neither @load nor @error is then guaranteed to fire — on Firefox
		 * neither does — so the spinner stayed up for good.
		 *
		 * @return {boolean}
		 */
		hasPreview() {
			return this.attachment !== null
				&& typeof this.attachment.preview_url === 'string'
				&& this.attachment.preview_url !== ''
		},

		/** @return {boolean} whether the still-image placeholder is on screen */
		showsPlaceholder() {
			return !this.isAv && this.attachment !== null && (this.previewFailed || !this.hasPreview)
		},

		/** @return {string} what the placeholder stands for */
		placeholderLabel() {
			const description = this.attachment?.description
			if (this.previewFailed) {
				return description
					? translate('social', 'Attachment could not be loaded: {description}', { description })
					: translate('social', 'Attachment could not be loaded')
			}

			return description
				? translate('social', 'No preview available: {description}', { description })
				: translate('social', 'No preview available')
		},
	},

	watch: {
		attachment() {
			this.previewLoaded = false
			this.previewFailed = false
			this.drawBlurhash()
		},
	},

	mounted() {
		this.drawBlurhash()
	},

	methods: {
		onPreviewError() {
			this.previewFailed = true
			this.previewLoaded = false
		},

		drawBlurhash() {
			if (this.isAv || this.attachment?.meta?.small?.width === undefined) {
				return
			}

			// CacheDocumentService sets the copy sizes before it knows GD could
			// read the image, so an unreadable upload arrives with dimensions
			// and an empty blurhash — and decode('') throws
			const blurhash = this.attachment.blurhash
			if (typeof blurhash !== 'string' || blurhash.length < 6) {
				return
			}

			if (!this.$refs.canvas) {
				return
			}

			try {
				const ctx = this.$refs.canvas.getContext('2d')
				const imageData = ctx.createImageData(this.attachment.meta.small.width, this.attachment.meta.small.height)
				const pixels = decode(blurhash, this.attachment.meta.small.width, this.attachment.meta.small.height)
				imageData.data.set(pixels)
				ctx.putImageData(imageData, 0, 0)
			} catch (error) {
				// a malformed hash is not worth losing the attachment over
				logger.debug('Could not draw the blurhash placeholder', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.attachment {
	position: relative;
	height: 100%;
	width: 100%;

	&__blurhash {
		position: absolute;
		/* Both edges, not just the top. An absolutely positioned box with no
		   inline offset keeps its *static* position, and the static position
		   here comes from a `<button>` — which centres its content by UA rule.
		   The picture was drawn half its own width to the right of its frame,
		   the empty half showing the frame's grey and the other half clipped
		   off the card. */
		inset-inline-start: 0;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 1;
		transition: opacity .4s ease;

		&--hidden {
			opacity: 0;
		}
	}

	&__preview {
		position: absolute;
		inset-inline-start: 0;
		top: 0;
		height: 100%;
		width: 100%;
		object-fit: cover;
		z-index: 2;

		/* the image resolves out of its own blur rather than replacing it */
		&--fading {
			opacity: 0;
			transform: scale(1.02);
			transition: opacity .4s ease, transform .4s ease;
		}

		&--shown {
			opacity: 1;
			transform: scale(1);
		}
	}

	&__failed {
		position: absolute;
		inset: 0;
		z-index: 3;
		display: flex;
		align-items: center;
		justify-content: center;
		color: var(--color-text-maxcontrast);
	}

	.loading-icon {
		position: absolute;
		top: calc(50% - 20px);
		inset-inline-start: calc(50% - 20px);
		z-index: 3;
	}
}

@media (prefers-reduced-motion: reduce) {
	.attachment__blurhash,
	.attachment__preview--fading {
		transition: none;
	}

	.attachment__preview--fading {
		transform: none;
	}
}
</style>

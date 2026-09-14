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
		<!-- `playsinline`, or iOS takes every video full screen the moment it
		     starts: the reader is thrown out of the timeline into a player,
		     and comes back to wherever the page has scrolled to meanwhile.
		     `controls` only where the media is the thing on screen. In a
		     timeline a post is a link to itself, so a player here would put a
		     play button in the way of it — and swallow the click that was
		     meant to open the post. The poster still shows, which is what the
		     reader is choosing from. -->
		<video
			v-if="attachment !== null && attachment.type === 'video'"
			class="attachment__preview"
			:src="interactive ? attachment.url : undefined"
			:poster="poster"
			:aria-label="attachment.description || ''"
			:controls="interactive"
			:preload="interactive ? preload : 'none'"
			playsinline
			@click="onMediaClick"
			@loadedmetadata="previewLoaded = true" />
		<audio
			v-else-if="attachment !== null && attachment.type === 'audio'"
			class="attachment__audio"
			:src="attachment.url"
			:aria-label="attachment.description || ''"
			:controls="interactive"
			preload="metadata"
			@click="onMediaClick"
			@loadedmetadata="previewLoaded = true" />
		<!-- a file: nothing to draw, so it is named. The whole card is the
		     link, since there is nothing else on it to press -->
		<a
			v-else-if="attachment !== null && attachment.type === 'unknown'"
			class="attachment__file"
			:href="attachment.url"
			target="_blank"
			rel="noopener"
			download
			@click.stop>
			<FileDocumentOutline :size="28" class="attachment__file-icon" />
			<span class="attachment__file-text">
				<span class="attachment__file-name">{{ fileName }}</span>
				<span v-if="fileKind" class="attachment__file-kind">{{ fileKind }}</span>
			</span>
		</a>
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
		<NcLoadingIcon v-if="attachment === null || (!previewLoaded && !showsPlaceholder && !isAv && !isFile)" :size="40" />
	</div>
</template>

<script>
import { decode } from 'blurhash'
import { translate } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ImageOff from 'vue-material-design-icons/ImageOff.vue'
import logger from '../services/logger.js'

export default {
	name: 'MediaAttachment',
	components: {
		FileDocumentOutline,
		ImageOff,
		NcLoadingIcon,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon').MediaAttachment>} */
		attachment: {
			type: Object,
			default: null,
		},

		/**
		 * Whether this is the copy the reader came to look at.
		 *
		 * On by default, because every caller but a timeline is showing the
		 * media for its own sake: the viewer, the composer's previews, a
		 * single post. Off in a timeline, where the whole post is a link to
		 * itself and a player would intercept the click.
		 */
		interactive: {
			type: Boolean,
			default: true,
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

		/** @return {boolean} whether this is a file rather than something to draw or play */
		isFile() {
			return this.attachment?.type === 'unknown'
		},

		/**
		 * @return {string} what the file is called: the description the server
		 * filled in from the upload's name, else the last bit of its address
		 */
		fileName() {
			const description = (this.attachment?.description || '').trim()
			if (description !== '') {
				return description
			}
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			return decodeURIComponent(path.split('/').pop() || '') || t('social', 'File')
		},

		/** @return {string} the extension, upper-cased, as a kind label; '' when there is none */
		fileKind() {
			const path = (this.attachment?.url || this.attachment?.remote_url || '').split('?')[0]
			const match = /\.([a-z0-9]{1,8})$/i.exec(path)
			return match ? match[1].toUpperCase() : ''
		},

		/**
		 * The still to show before anything is played. A federated video has
		 * one -- PeerTube publishes it and this instance mirrors it -- and it
		 * is the whole reason a video timeline can be scrolled without
		 * fetching a frame of anything.
		 *
		 * `preview_url` is the file itself for a video uploaded here, which is
		 * no use as a poster: a browser handed a video for one downloads it to
		 * find a frame, which is what the poster exists to avoid. So only a
		 * preview that differs from the source counts as one.
		 *
		 * @return {string|undefined} undefined leaves the attribute off
		 */
		poster() {
			const preview = this.attachment?.preview_url
			if (typeof preview !== 'string' || preview === '' || preview === this.attachment?.url) {
				return undefined
			}

			return preview
		},

		/**
		 * How much of the video to fetch before anybody has asked to watch it.
		 *
		 * With a poster, nothing: the frame is already on screen, and on a page
		 * of twenty federated videos `metadata` alone would open twenty
		 * connections to other servers through this one. Without a poster there
		 * is nothing to draw until the first frame arrives, so it is worth the
		 * headers.
		 *
		 * @return {string}
		 */
		preload() {
			return this.poster === undefined ? 'metadata' : 'none'
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
		/**
		 * A press on the player itself.
		 *
		 * Where the media is interactive the player handles it and nothing
		 * above needs to know — a press on `play` is not a press on the post.
		 * Where it is not, the press belongs to whatever wraps this, which in
		 * a timeline is the link to the post.
		 *
		 * @param {Event} event the press
		 */
		onMediaClick(event) {
			if (this.interactive) {
				event.stopPropagation()
			}
		},

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
/* the card a file is shown as: its name, what kind it is, and the whole of
   it a link to the download */
.attachment__file {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	min-height: 64px;
	padding: 12px 16px;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	text-decoration: none;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
	}
}

.attachment__file-icon {
	flex: none;
	color: var(--color-primary-element);
}

.attachment__file-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.attachment__file-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.attachment__file-kind {
	font-size: 12px;
	letter-spacing: .04em;
	color: var(--color-text-maxcontrast);
}

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

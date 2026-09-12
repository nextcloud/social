<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure
		class="photo"
		:class="`photo--${fit}`"
		:style="frameStyle">
		<!-- video and audio carry their own controls: nesting those inside a
		     button is invalid, and they need no viewer to be watchable here -->
		<button
			v-if="pressable"
			type="button"
			class="photo__open"
			:aria-label="openLabel"
			@click="$emit('open')">
			<MediaAttachment :attachment="attachment" />
		</button>
		<MediaAttachment v-else :attachment="attachment" />
		<!-- the description is what the picture actually is, and a reader who
		     cannot see the picture is not the only one who wants it -->
		<template v-if="hasDescription">
			<button
				type="button"
				class="photo__alt"
				:aria-expanded="descriptionShown ? 'true' : 'false'"
				:aria-controls="descriptionId"
				:title="t('social', 'Image description')"
				@click="descriptionShown = !descriptionShown">
				{{ t('social', 'ALT') }}
			</button>
			<figcaption
				v-show="descriptionShown"
				:id="descriptionId"
				class="photo__description">
				{{ attachment.description }}
			</figcaption>
		</template>
	</figure>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import MediaAttachment from './MediaAttachment.vue'
import { ratioOf } from './GalleryRatio.js'

/** ids have to be unique per document, and one post may show eight pictures */
let sequence = 0

export default {
	name: 'GalleryMedia',
	components: {
		MediaAttachment,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').MediaAttachment>} */
		attachment: {
			type: Object,
			required: true,
		},

		/** its place in the post, for the label of media without a description */
		index: {
			type: Number,
			default: 0,
		},

		total: {
			type: Number,
			default: 1,
		},

		/**
		 * `cover` fills a box shaped like the picture itself; `contain` fits it
		 * into a box shaped like something else, which is what a carousel of
		 * mixed portraits and landscapes needs.
		 */
		fit: {
			type: String,
			default: 'cover',
			validator: (value) => ['cover', 'contain'].includes(value),
		},

		/** the box to reserve, overriding the picture's own shape; 0 to derive it */
		ratio: {
			type: Number,
			default: 0,
		},
	},

	emits: ['open'],
	data() {
		return {
			descriptionShown: false,
			descriptionId: `social-alt-${sequence++}`,
		}
	},

	computed: {
		/** @return {boolean} */
		hasDescription() {
			return typeof this.attachment.description === 'string'
				&& this.attachment.description.trim() !== ''
		},

		/** @return {boolean} whether pressing it should open the viewer */
		pressable() {
			return this.attachment.type !== 'video' && this.attachment.type !== 'audio'
		},

		/** @return {boolean} audio has no picture to reserve room for */
		framed() {
			return this.attachment.type !== 'audio'
		},

		/** @return {object} */
		frameStyle() {
			if (!this.framed) {
				return {}
			}

			return { aspectRatio: String(this.ratio > 0 ? this.ratio : ratioOf(this.attachment)) }
		},

		/** @return {string} what opening this attachment will show */
		openLabel() {
			return this.hasDescription
				? translate('social', 'Open attachment: {description}', { description: this.attachment.description })
				: translate('social', 'Open attachment {number} of {total}', {
						number: this.index + 1,
						total: this.total,
					})
		},
	},

	watch: {
		attachment() {
			this.descriptionShown = false
		},
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.photo {
	position: relative;
	margin: 0;
	width: 100%;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);

	&--contain {
		:deep(.attachment__preview) {
			object-fit: contain;
		}
	}

	&__open {
		display: block;
		padding: 0;
		margin: 0;
		border: none;
		width: 100%;
		height: 100%;
		background: transparent;
		cursor: zoom-in;
	}

	&__alt {
		position: absolute;
		inset-block-end: 8px;
		inset-inline-start: 8px;
		z-index: 4;
		padding: 2px 8px;
		min-height: 0;
		font-size: 11px;
		font-weight: 700;
		letter-spacing: .04em;
		line-height: 1.5;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius, 6px);
		color: var(--color-main-text);
		background: var(--color-main-background);
		opacity: .9;

		&:hover,
		&:focus-visible {
			opacity: 1;
			border-color: var(--color-primary-element);
		}
	}

	&__description {
		position: absolute;
		inset-inline: 0;
		inset-block-end: 0;
		z-index: 3;
		max-height: 60%;
		overflow-y: auto;
		padding: 8px 8px 34px;
		font-size: 13px;
		line-height: 1.45;
		text-align: start;
		color: var(--color-main-text);
		background: var(--color-main-background);
		border-block-start: 1px solid var(--color-border);
	}
}
</style>

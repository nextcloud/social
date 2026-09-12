<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="media-grid">
		<ul v-if="tiles.length" class="media-grid__list">
			<li v-for="tile in tiles" :key="tile.key" class="media-grid__cell">
				<router-link
					class="media-grid__link"
					:to="tile.route"
					:aria-label="tile.label">
					<img
						v-if="tile.preview"
						class="media-grid__image"
						:src="tile.preview"
						:alt="tile.alt"
						:style="tile.style"
						loading="lazy"
						decoding="async"
						@error="onImageError(tile.key)">
					<span v-else class="media-grid__missing" aria-hidden="true">
						<ImageOffOutline :size="24" />
					</span>

					<!-- an album is one tile; say so, as Pixelfed does -->
					<span v-if="tile.count > 1" class="media-grid__badge" aria-hidden="true">
						<ImageMultipleOutline :size="16" />
					</span>
					<span v-else-if="tile.isVideo" class="media-grid__badge" aria-hidden="true">
						<PlayCircleOutline :size="16" />
					</span>
					<span v-if="tile.sensitive" class="media-grid__veil" aria-hidden="true">
						<EyeOffOutline :size="20" />
					</span>
				</router-link>
			</li>
		</ul>

		<NcEmptyContent
			v-else-if="!loading"
			:name="t('social', 'No photos yet')"
			:description="t('social', 'Posts with pictures appear here.')">
			<template #icon>
				<ImageMultipleOutline />
			</template>
		</NcEmptyContent>

		<NcLoadingIcon v-if="loading" class="media-grid__loading" :size="32" />
	</div>
</template>

<script>
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import ImageMultipleOutline from 'vue-material-design-icons/ImageMultipleOutline.vue'
import ImageOffOutline from 'vue-material-design-icons/ImageOffOutline.vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import { t } from '@nextcloud/l10n'

/**
 * A profile as a grid of squares, which is what a profile looks like on
 * Pixelfed and is the single most visible difference between the two apps.
 *
 * One tile per post, not per picture: an album is one thing somebody posted and
 * tapping it opens that post, so a ten-picture album taking ten tiles would
 * make a profile look like ten posts. The badge says there is more behind it.
 *
 * The crop is where the focal point says, not the middle. That is the whole
 * reason the focal point exists -- a square crop of a portrait photo cuts the
 * subject out of it about half the time, and `object-position` is what puts it
 * back. A post that never set one is centred, which is what every client
 * assumes when the field is absent.
 */
export default {
	name: 'ProfileMediaGrid',

	components: {
		EyeOffOutline,
		ImageMultipleOutline,
		ImageOffOutline,
		NcEmptyContent,
		NcLoadingIcon,
		PlayCircleOutline,
	},

	props: {
		/** The posts to draw; those without pictures are skipped. */
		posts: {
			type: Array,
			default: () => [],
		},

		account: {
			type: String,
			required: true,
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			/** Tiles whose picture 404'd, so the next render draws the fallback. */
			broken: [],
		}
	},

	computed: {
		tiles() {
			return this.posts
				.filter((post) => post && Array.isArray(post.media_attachments) && post.media_attachments.length > 0)
				.map((post) => this.toTile(post))
		},
	},

	methods: {
		t,

		/**
		 * @param {object} post one status
		 * @return {object} what the template needs, worked out once
		 */
		toTile(post) {
			const first = post.media_attachments[0]
			const key = String(post.id)
			const broken = this.broken.includes(key)

			return {
				key,
				count: post.media_attachments.length,
				isVideo: first.type === 'video' || first.type === 'gifv',
				sensitive: Boolean(post.sensitive),
				preview: broken ? null : (first.preview_url || first.url || null),
				alt: first.description || '',
				label: this.labelFor(post, first),
				style: { objectPosition: this.focalPosition(first) },
				route: {
					name: 'single-post',
					params: { account: this.account, id: post.id },
				},
			}
		},

		/**
		 * `focus` is a pair from -1 to 1 with the origin at the centre and y
		 * pointing *up*; CSS wants two percentages from the top left. So x maps
		 * straight across and y is inverted.
		 *
		 * @param {object} attachment the first attachment of the post
		 * @return {string} an `object-position` value
		 */
		focalPosition(attachment) {
			const focus = attachment?.meta?.focus
			if (!focus || typeof focus.x !== 'number' || typeof focus.y !== 'number') {
				return '50% 50%'
			}

			const x = (focus.x + 1) / 2 * 100
			const y = (1 - focus.y) / 2 * 100

			return `${x.toFixed(2)}% ${y.toFixed(2)}%`
		},

		/**
		 * @param {object} post the status
		 * @param {object} attachment its first attachment
		 * @return {string} what a screen reader announces for the tile
		 */
		labelFor(post, attachment) {
			if (attachment.description) {
				return attachment.description
			}

			return post.media_attachments.length > 1
				? t('social', 'Post with {count} pictures', { count: post.media_attachments.length })
				: t('social', 'Post with a picture')
		},

		onImageError(key) {
			if (!this.broken.includes(key)) {
				this.broken.push(key)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.media-grid {
	&__list {
		display: grid;
		// three across is Pixelfed's; auto-fill keeps the squares a sensible
		// size on a narrow screen instead of shrinking them to nothing
		grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
		gap: 2px;
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__cell {
		position: relative;
		// the square the whole thing is for
		aspect-ratio: 1 / 1;
		overflow: hidden;
		background-color: var(--color-background-dark);
	}

	&__link {
		display: block;
		width: 100%;
		height: 100%;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__image {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
		transition: transform 0.15s ease-out;

		.media-grid__link:hover &,
		.media-grid__link:focus-visible & {
			transform: scale(1.03);
		}
	}

	&__missing {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: var(--color-text-maxcontrast);
	}

	&__badge {
		position: absolute;
		top: 6px;
		inset-inline-end: 6px;
		color: #fff;
		// the pictures underneath are arbitrary, so the icon carries its own
		// contrast rather than relying on them
		filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.6));
		pointer-events: none;
	}

	&__veil {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		color: #fff;
		background-color: rgba(0, 0, 0, 0.5);
		backdrop-filter: blur(12px);
		pointer-events: none;
	}

	&__loading {
		margin: 16px auto;
	}
}

@media (prefers-reduced-motion: reduce) {
	.media-grid__image {
		transition: none;

		.media-grid__link:hover &,
		.media-grid__link:focus-visible & {
			transform: none;
		}
	}
}
</style>

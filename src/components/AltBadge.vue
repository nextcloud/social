<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- two roots on purpose: the badge and the text it opens are both
	     positioned against the frame the picture is in, and a wrapper of its
	     own between them and that frame would have to be given its size to
	     stay out of the way -->
	<button
		type="button"
		class="alt-badge"
		:aria-expanded="shown ? 'true' : 'false'"
		:aria-controls="descriptionId"
		:title="t('social', 'Image description')"
		@click.stop="shown = !shown">
		{{ t('social', 'ALT') }}
	</button>
	<p
		v-show="shown"
		:id="descriptionId"
		class="alt-description">
		{{ description }}
	</p>
</template>

<script>
import { translate } from '@nextcloud/l10n'

/** ids have to be unique per document, and one post may show eight pictures */
let sequence = 0

/**
 * The ALT badge: what a picture is, for anybody who wants to know.
 *
 * A description is written for the reader who cannot see the picture, but it
 * is worth reading either way — what is in a photograph is often the point of
 * posting it. Mastodon marks described media with a badge for that reason, and
 * this app marked only the media-first mosaic: the same picture in a two-up
 * timeline card carried its description in the `alt` attribute and nowhere a
 * sighted reader could reach it. One component, so the badge is in the same
 * corner, in the same words, wherever a thumbnail carries a description.
 *
 * It positions itself against whatever frame it is put in, which therefore has
 * to be `position: relative` -- `.photo` in GalleryMedia, `.attachment-frame`
 * in PostAttachment.
 */
export default {
	name: 'AltBadge',

	props: {
		/** The description to reveal. Only rendered where there is one. */
		description: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			shown: false,
			descriptionId: `social-alt-${sequence++}`,
		}
	},

	watch: {
		// a different picture in the same frame is a different description,
		// and it should not arrive already open
		description() {
			this.shown = false
		},
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.alt-badge {
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

.alt-description {
	position: absolute;
	inset-inline: 0;
	inset-block-end: 0;
	z-index: 3;
	max-height: 60%;
	overflow-y: auto;
	margin: 0;
	padding: 8px 8px 34px;
	font-size: 13px;
	line-height: 1.45;
	text-align: start;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border-block-start: 1px solid var(--color-border);
}
</style>

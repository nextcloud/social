<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="composer-preview" aria-live="polite">
		<p class="composer-preview__caption">
			{{ t('social', 'How this will read') }}
		</p>
		<div v-if="warning" class="composer-preview__warning">
			{{ warning }}
		</div>
		<p v-if="runs.length === 0" class="composer-preview__empty">
			{{ t('social', 'Nothing written yet.') }}
		</p>
		<p v-else class="composer-preview__body">
			<!-- The text is drawn as its runs rather than as HTML: nothing here
			     is ever parsed as markup, so a post that is all angle brackets
			     is shown as the angle brackets that will be published.

			     Every run is a span, plain text included, and each one's text
			     sits against its tags: the body is `pre-wrap`, so a newline
			     put here to make the markup prettier would be a space in the
			     preview that is not in the post. -->
			<span
				v-for="(run, index) in runs"
				:key="index"
				:class="run.type === 'text' ? null : ['composer-preview__entity', `composer-preview__entity--${run.type}`]"
				:style="run.type === 'hashtag' ? tagStyle(run.name) : null">{{ run.text }}</span>
		</p>
		<p class="composer-preview__note">
			{{ summary }}
		</p>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { linkifyRuns } from '../../utils/linkify.js'
import { tagStyle } from '../../utils/tagColour.js'

/**
 * What the post will look like once it is published.
 *
 * A post is typed as plain text and published as HTML: the server finds the
 * URLs, mentions and hashtags and links them (LinkifyService). Until this
 * existed the writer found out what had been picked up only after posting —
 * and a hashtag that did not take, or a mention that ran into the next word,
 * is exactly the kind of thing worth knowing before rather than after.
 *
 * The entities are found by utils/linkify.js, which mirrors the server's own
 * rules and is pinned to them by its tests. What this cannot know is whether a
 * mention resolves to a real account: the server only links one its `tag`
 * array vouches for, and it builds that array by looking the account up. So
 * this says "this is a mention", never "this account exists", and the wording
 * under the preview says as much.
 */
export default {
	name: 'ComposerPreview',

	props: {
		/** the text as it has been typed */
		text: {
			type: String,
			default: '',
		},

		/** the content warning, when the post carries one */
		warning: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @return {Array<{type: string, text: string, name?: string}>} the text
		 *         broken into plain stretches and the things that become links
		 */
		runs() {
			return linkifyRuns(this.text)
		},

		/**
		 * @return {{mentions: number, hashtags: number, links: number}} how many
		 *         of each the post carries
		 */
		counts() {
			const counts = { mentions: 0, hashtags: 0, links: 0 }

			for (const run of this.runs) {
				if (run.type === 'mention') {
					counts.mentions++
				} else if (run.type === 'hashtag') {
					counts.hashtags++
				} else if (run.type === 'url') {
					counts.links++
				}
			}

			return counts
		},

		/**
		 * What was found, in words. A count of nothing is worth saying too:
		 * "no hashtags" is the answer to why a post did not reach a tag
		 * timeline.
		 *
		 * @return {string} the summary under the preview
		 */
		summary() {
			const { mentions, hashtags, links } = this.counts

			if (mentions === 0 && hashtags === 0 && links === 0) {
				return t('social', 'No mentions, hashtags or links yet.')
			}

			const parts = []
			if (mentions > 0) {
				parts.push(n('social', '%n mention', '%n mentions', mentions))
			}
			if (hashtags > 0) {
				parts.push(n('social', '%n hashtag', '%n hashtags', hashtags))
			}
			if (links > 0) {
				parts.push(n('social', '%n link', '%n links', links))
			}

			return t('social', 'Will be published with {entities}.', { entities: parts.join(', ') })
		},
	},

	methods: {
		t,
		n,
		tagStyle,
	},
}
</script>

<style lang="scss" scoped>
.composer-preview {
	margin: 6px 0;
	padding: 10px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-hover);
}

.composer-preview__caption {
	margin: 0 0 6px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .04em;
}

/* drawn the way a warning is drawn on a card, so the preview of a post with
   one looks like the card it is going to become */
.composer-preview__warning {
	margin-bottom: 6px;
	padding: 4px 8px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-weight: 600;
	font-size: 13px;
}

.composer-preview__body {
	margin: 0;
	color: var(--color-main-text);
	font-size: 14px;
	line-height: 1.5;
	/* a post keeps the line breaks that were typed, and a long URL must not
	   push the composer wider than its column */
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.composer-preview__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-style: italic;
}

.composer-preview__entity {
	color: var(--color-primary-element);
	font-weight: 600;
}

/* a hashtag is shown in the colour it will have wherever it is met */
.composer-preview__entity--hashtag {
	color: var(--tag-colour, var(--color-primary-element));
}

.composer-preview__entity--url {
	text-decoration: underline;
}

.composer-preview__note {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

@media (prefers-color-scheme: dark) {
	.composer-preview__entity--hashtag {
		color: var(--tag-colour-dark, var(--color-primary-element));
	}
}

[data-themes*='dark'] .composer-preview__entity--hashtag {
	color: var(--tag-colour-dark, var(--color-primary-element));
}
</style>

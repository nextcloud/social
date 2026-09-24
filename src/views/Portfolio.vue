<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="portfolio">
		<NcLoadingIcon v-if="loading" class="portfolio__loading" :size="32" />

		<NcEmptyContent
			v-else-if="missing"
			:name="t('social', 'No portfolio here')"
			:description="t('social', 'This account has not published one.')">
			<template #icon>
				<IconImageFrame :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<header class="portfolio__head">
				<img
					v-if="portfolio.show_avatar && account.avatar"
					class="portfolio__avatar"
					:src="account.avatar"
					alt="">
				<h1 class="portfolio__title">
					{{ portfolio.title || account.display_name || account.username }}
				</h1>
				<p v-if="portfolio.intro" class="portfolio__intro">
					{{ portfolio.intro }}
				</p>
				<p class="portfolio__by">
					<a class="portfolio__by-link" :href="webLink(account.url)">{{ '@' + account.acct }}</a>
				</p>
			</header>

			<ul v-if="posts.length" class="portfolio__works" :class="`portfolio__works--${portfolio.layout}`">
				<li v-for="post in posts" :key="post.id" class="portfolio__work">
					<!-- the address a remote server chose, so it is checked
					     before it becomes something a reader can click -->
					<a class="portfolio__frame" :href="linkOf(post)">
						<img
							class="portfolio__image"
							:src="pictureOf(post)"
							:alt="altOf(post)"
							loading="lazy">
					</a>
					<p v-if="portfolio.show_captions && captionOf(post)" class="portfolio__caption">
						{{ captionOf(post) }}
					</p>
					<p v-if="footerOf(post)" class="portfolio__meta">
						{{ footerOf(post) }}
					</p>
				</li>
			</ul>

			<NcEmptyContent
				v-else
				:name="t('social', 'Nothing on it yet')"
				:description="t('social', 'The pictures this page shows are chosen in Settings.')">
				<template #icon>
					<IconImageFrame :size="20" />
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import IconImageFrame from 'vue-material-design-icons/ImageFrame.vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import logger from '../services/logger.js'
import { htmlToPlainText } from '../utils/plainText.js'

/**
 * Somebody's page of work.
 *
 * A profile is a feed; this is the opposite — a title, a sentence, a set of
 * pictures and nothing else. No follow button, no counts, no actions: what it
 * is for is being linked from a CV, and everything that makes a profile a
 * profile is noise on a page read by somebody deciding whether to hire a
 * photographer.
 *
 * It asks the public route, which resolves no viewer at all, so what it draws
 * is what the whole internet may see whoever is reading. A page its owner has
 * not published is the same "no portfolio here" as an account that has none.
 */
export default {
	name: 'Portfolio',

	components: {
		IconImageFrame,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			portfolio: {},
			loading: true,
			missing: false,
		}
	},

	computed: {
		/** @return {object} */
		account() {
			return this.portfolio.account ?? {}
		},

		/** @return {Array} */
		posts() {
			return (this.portfolio.posts ?? []).filter((post) => this.pictureOf(post) !== '')
		},
	},

	watch: {
		'$route.params.account': 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * An address only if it is one a browser may safely follow.
		 *
		 * Every value here was chosen by whichever server the post came from.
		 * The server refuses the schemes that do something when clicked, and
		 * this refuses them again: a page that is served to anonymous readers
		 * is the wrong place to rely on one check.
		 *
		 * @param {string} address as the entity carried it
		 * @return {string|undefined} the address, or nothing to link to
		 */
		webLink(address) {
			return /^https?:\/\//i.test(String(address ?? '')) ? address : undefined
		},

		/**
		 * Where a picture in the portfolio leads: the page the author's server
		 * names, or its id when that is all there is.
		 *
		 * @param {object} post the portfolio entry
		 * @return {string|undefined} the address, or nothing to link to
		 */
		linkOf(post) {
			return this.webLink(post.url) ?? this.webLink(post.uri)
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			this.missing = false
			try {
				const account = this.$route.params.account
				const url = generateUrl('apps/social/api/v1.1/portfolio/{account}', { account })
				const { data } = await axios.get(url)
				this.portfolio = data
			} catch (error) {
				// an account with no published page and one that does not exist
				// are the same answer, which is what the server says too
				logger.debug('there is no portfolio to show', { error })
				this.missing = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} post one of them
		 * @return {string} the picture to draw, or '' when there is none
		 */
		pictureOf(post) {
			const media = (post.media_attachments ?? []).find((one) => one.type === 'image')

			return media?.preview_url || media?.url || ''
		},

		/**
		 * @param {object} post one of them
		 * @return {string}
		 */
		altOf(post) {
			const media = (post.media_attachments ?? []).find((one) => one.type === 'image')

			return media?.description || this.captionOf(post) || ''
		},

		/**
		 * @param {object} post one of them
		 * @return {string} its words, as words
		 */
		captionOf(post) {
			return htmlToPlainText(post.content ?? '').trim()
		},

		/**
		 * Where and when, as the owner asked for them.
		 *
		 * One line rather than two fields, because on a page whose subject is
		 * the picture both of them are a footnote.
		 *
		 * @param {object} post one of them
		 * @return {string}
		 */
		footerOf(post) {
			const parts = []
			if (this.portfolio.show_places && post.place?.name) {
				parts.push(post.place.country ? `${post.place.name}, ${post.place.country}` : post.place.name)
			}
			if (this.portfolio.show_dates && post.created_at) {
				const when = new Date(post.created_at)
				if (!Number.isNaN(when.getTime())) {
					parts.push(when.getFullYear().toString())
				}
			}

			return parts.join(' · ')
		},
	},
}
</script>

<style scoped lang="scss">
.portfolio {
	max-width: 1100px;
	margin-inline: auto;
	padding: 24px 16px 48px;
}

.portfolio__loading {
	margin-block: 48px;
}

.portfolio__head {
	text-align: center;
	margin-block-end: 32px;
}

.portfolio__avatar {
	width: 72px;
	height: 72px;
	border-radius: 50%;
	object-fit: cover;
	margin-block-end: 8px;
}

.portfolio__title {
	font-size: 28px;
	line-height: 1.2;
	margin: 0;
}

.portfolio__intro {
	max-width: 46em;
	margin: 8px auto 0;
	color: var(--color-text-maxcontrast);
	/*
	 * The intro and the captions below are interpolated as *text* — the intro
	 * is what its owner typed, and a caption is `htmlToPlainText()` of a post
	 * — so the paragraph breaks in them are newlines rather than markup, and
	 * HTML collapses a newline to a space. Somebody's three paragraphs
	 * therefore arrived as one run-on line. Not rendered as HTML instead: the
	 * intro is a plain-text field and a caption is deliberately stripped of
	 * the markup a post carries, so `pre-line` is what keeps the breaks
	 * without putting either back.
	 */
	white-space: pre-line;
}

.portfolio__by {
	margin-block: 8px 0;
}

.portfolio__by-link {
	color: var(--color-text-maxcontrast);
	text-decoration: none;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.portfolio__works {
	list-style: none;
	margin: 0;
	padding: 0;
}

// the grid: squares, which is what a contact sheet is
.portfolio__works--grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 16px;

	.portfolio__image {
		aspect-ratio: 1;
		object-fit: cover;
	}
}

// and the rows: one picture at a time, at its own shape, which is how a
// photographer would rather show a panorama
.portfolio__works--rows {
	display: flex;
	flex-direction: column;
	gap: 40px;

	.portfolio__work {
		max-width: 900px;
		margin-inline: auto;
		width: 100%;
	}
}

.portfolio__frame {
	display: block;
}

.portfolio__image {
	display: block;
	width: 100%;
	max-width: 100%;
	height: auto;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
}

.portfolio__caption {
	margin-block: 8px 0;
	/* see `.portfolio__intro` */
	white-space: pre-line;
}

.portfolio__meta {
	margin-block: 2px 0;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}
</style>

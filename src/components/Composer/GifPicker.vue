<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="gif-picker">
		<div class="gif-picker__head">
			<NcTextField
				:modelValue="term"
				class="gif-picker__search"
				:label="t('social', 'Search the picture library')"
				:showTrailingButton="false"
				@update:modelValue="onSearch" />
			<NcButton
				variant="tertiary"
				:title="t('social', 'Close the picture library')"
				:aria-label="t('social', 'Close the picture library')"
				@click="$emit('close')">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>

		<p v-if="loading" class="gif-picker__note">
			{{ t('social', 'Loading…') }}
		</p>
		<p v-else-if="gifs.length === 0" class="gif-picker__note">
			{{ emptyMessage }}
		</p>
		<ul v-else class="gif-picker__grid">
			<li v-for="gif in gifs" :key="gif.slug">
				<button
					type="button"
					class="gif-picker__item"
					:disabled="busy"
					:title="gif.title || gif.slug"
					:aria-label="t('social', 'Attach {name}', { name: gif.title || gif.slug })"
					@click="choose(gif)">
					<!-- `loading="lazy"`: the whole library is in this grid and
					     most of it is below the fold, and these are animations -->
					<img
						class="gif-picker__image"
						:src="gif.url"
						:alt="gif.title || gif.slug"
						loading="lazy">
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../../services/logger.js'

/** how long the search waits after the last keystroke */
const DEBOUNCE = 250

/**
 * The instance's shared picture library, as a grid in the composer.
 *
 * The pictures are ones an administrator put there (`occ social:gif add`), so
 * this is the same set for everybody on the instance — which is what makes it
 * the same joke in the same team rather than one person's collection, and is
 * the whole reason it is not simply the Files picker that sits beside it.
 *
 * Choosing one does not download and re-upload it: the server copies the
 * library file into an attachment, through the same path an upload takes. See
 * `/api/v1/media/from-gif`.
 */
export default {
	name: 'GifPicker',

	components: {
		Close,
		NcButton,
		NcTextField,
	},

	emits: ['close', 'chosen'],

	data() {
		return {
			term: '',
			gifs: [],
			loading: true,
			/** true while an attachment is being made, so nothing is chosen twice */
			busy: false,
		}
	},

	computed: {
		/**
		 * An empty library and a search that found nothing are different
		 * things, and only one of them is something the reader can fix.
		 *
		 * @return {string} what to say
		 */
		emptyMessage() {
			return this.term === ''
				? t('social', 'Nobody has added any pictures to this instance yet.')
				: t('social', 'Nothing here matches that.')
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.debounce)
	},

	methods: {
		t,

		/**
		 * @param {string} term what has been typed so far
		 */
		onSearch(term) {
			this.term = term
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.load(), DEBOUNCE)
		},

		/** Reads the library, or the part of it that matches. */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/gifs'), {
					params: { q: this.term },
				})

				this.gifs = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Could not read the picture library', { error })
				this.gifs = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {{slug: string, title: string}} gif the one that was pressed
		 */
		async choose(gif) {
			if (this.busy) {
				return
			}

			this.busy = true
			try {
				this.$emit('chosen', gif)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.gif-picker {
	margin: 6px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-hover);
}

.gif-picker__head {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.gif-picker__search {
	flex: 1 1 auto;
}

.gif-picker__note {
	margin: 8px 2px 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gif-picker__grid {
	display: grid;
	/* as many as fit, each at least this wide; no media query decides how many
	   columns there are, because the composer is in a column whose width
	   depends on the window and on the sidebar */
	grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
	gap: 4px;
	/* a library of any size is scrolled inside the picker rather than pushing
	   the box the post is being written in off the screen */
	max-height: 260px;
	margin: 8px 0 0;
	padding: 0;
	overflow-y: auto;
	list-style: none;
}

.gif-picker__item {
	display: block;
	width: 100%;
	padding: 0;
	border: 1px solid transparent;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		opacity: .6;
		cursor: default;
	}
}

.gif-picker__image {
	display: block;
	width: 100%;
	height: 90px;
	border-radius: var(--border-radius, 8px);
	object-fit: cover;
}
</style>

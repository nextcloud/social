<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="reactions.length || canReact" class="reaction-bar">
		<button
			v-for="reaction in reactions"
			:key="reaction.name"
			type="button"
			class="reaction"
			:class="{ 'reaction--mine': reaction.me, 'reaction--busy': busy === reaction.name }"
			:disabled="!canReact || busy !== ''"
			:aria-pressed="reaction.me ? 'true' : 'false'"
			:aria-label="labelFor(reaction)"
			@click.stop="toggle(reaction.name)">
			<span class="reaction__emoji" aria-hidden="true">{{ reaction.name }}</span>
			<span class="reaction__count" aria-hidden="true">{{ reaction.count }}</span>
		</button>

		<!-- The picker carries the whole emoji set — 130 KB over the wire — so
		     it is fetched when somebody first asks for it rather than by every
		     reader of every timeline, the same way the composer does it. -->
		<NcEmojiPicker
			v-if="pickerLoaded"
			:closeOnSelect="true"
			container="#content-vue"
			@select="react">
			<button
				type="button"
				class="reaction reaction--add"
				:disabled="busy !== ''"
				:aria-haspopup="true"
				:aria-label="t('social', 'Add a reaction')"
				:title="t('social', 'Add a reaction')"
				@click.stop>
				<EmoticonPlusOutline :size="16" />
			</button>
		</NcEmojiPicker>
		<button
			v-else-if="canReact"
			type="button"
			class="reaction reaction--add"
			:disabled="pickerLoading"
			:aria-haspopup="true"
			:aria-label="t('social', 'Add a reaction')"
			:title="t('social', 'Add a reaction')"
			@click.stop="loadPicker">
			<NcLoadingIcon v-if="pickerLoading" :size="16" />
			<EmoticonPlusOutline v-else :size="16" />
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { defineAsyncComponent } from 'vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/** Fetched once for the page, however many cards ask for it. */
let emojiPicker = null
const emojiPickerModule = () => (emojiPicker ??= import('@nextcloud/vue/components/NcEmojiPicker'))

/**
 * The emoji reactions under a post.
 *
 * A like says "yes" and nothing else. A reaction says which kind of yes, which
 * on an internal feed is most of what people want to say about a colleague's
 * post — and it federates: Misskey, Pleroma, Akkoma, Iceshrimp and Sharkey all
 * send and understand `EmojiReact`.
 *
 * The bar is drawn from what the server sent with the post, and each press
 * answers with the post again, so the counts a reader sees are the ones the
 * next reader will be served rather than a local guess. The press is not
 * optimistic for that reason: a reaction is a federated write that can be
 * refused — an emoji the server will not take, a suspended account — and a
 * count that jumped and then jumped back is worse than one that waits a moment.
 *
 * Unicode emoji only. The picker offers nothing else, and the server refuses
 * anything else, for the reason ReactionService gives: a custom `:shortcode:`
 * means drawing a picture from somebody else's server inside the bar.
 */
export default {
	name: 'ReactionBar',

	components: {
		EmoticonPlusOutline,
		NcLoadingIcon,
		NcEmojiPicker: defineAsyncComponent({
			loader: emojiPickerModule,
			onError: (error) => logger.error('Could not load the emoji picker', { error }),
		}),
	},

	props: {
		/** the post's numeric id, which is what the routes take */
		nid: {
			type: [Number, String],
			default: 0,
		},

		/**
		 * The bar as the server sent it: `{name, count, me}` each.
		 *
		 * @type {import('vue').PropType<Array<{name: string, count: number, me: boolean}>>}
		 */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** whether this reader may react at all; false on the public page */
		canReact: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			/** which emoji is in flight, '' when none */
			busy: '',
			pickerLoaded: false,
			pickerLoading: false,
		}
	},

	computed: {
		/** @return {Array<{name: string, count: number, me: boolean}>} */
		reactions() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		t,
		n,

		/**
		 * What a screen reader is told. The emoji itself is hidden from the
		 * accessibility tree: read aloud it is a name that may mean nothing
		 * here, and the useful part is how many people chose it and whether
		 * pressing this takes the reader's own back.
		 *
		 * @param {{name: string, count: number, me: boolean}} reaction one entry
		 * @return {string} the label
		 */
		labelFor(reaction) {
			const count = n('social', '%n reaction', '%n reactions', reaction.count)

			return reaction.me
				? t('social', 'Remove your {emoji} reaction ({count})', { emoji: reaction.name, count })
				: t('social', 'React with {emoji} ({count})', { emoji: reaction.name, count })
		},

		/**
		 * Fetches the picker and opens it, which is what the button that was
		 * pressed would have done had it been there.
		 */
		async loadPicker() {
			if (this.pickerLoaded || this.pickerLoading) {
				return
			}

			this.pickerLoading = true
			try {
				await emojiPickerModule()
				this.pickerLoaded = true
				await this.$nextTick()
				// the real button has replaced this one by now; it has to be
				// pressed for the popover to open, which is what the reader
				// was asking for when they pressed the placeholder
				this.$el.querySelector('.reaction--add')?.click()
			} catch (error) {
				logger.error('Could not load the emoji picker', { error })
				showError(t('social', 'Could not open the emoji picker'))
			} finally {
				this.pickerLoading = false
			}
		},

		/**
		 * @param {object|string} chosen what the picker handed over — its
		 *        `select` passes the emoji as a string, but a native object
		 *        with `native` is what older versions gave
		 */
		react(chosen) {
			const emoji = typeof chosen === 'string' ? chosen : (chosen?.native ?? '')
			if (emoji !== '') {
				this.send(emoji, true)
			}
		},

		/**
		 * @param {string} emoji the one that was pressed
		 */
		toggle(emoji) {
			const existing = this.reactions.find((reaction) => reaction.name === emoji)
			this.send(emoji, !existing?.me)
		},

		/**
		 * @param {string} emoji what to react with
		 * @param {boolean} add whether to add it or take it back
		 */
		async send(emoji, add) {
			if (!this.canReact || this.busy !== '') {
				return
			}

			this.busy = emoji
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/social/api/v1/statuses/${this.nid}/${add ? 'react' : 'unreact'}`),
					{ emoji },
				)

				this.$emit('update:modelValue', Array.isArray(data?.reactions) ? data.reactions : [])
			} catch (error) {
				logger.error('Could not change the reaction', { error })
				showError(add
					? t('social', 'Could not add that reaction')
					: t('social', 'Could not remove that reaction'))
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.reaction-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 6px;
}

.reaction {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 24px;
	padding: 1px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 13px;
	line-height: 20px;
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		cursor: default;
	}
}

/* the ones the reader chose themselves, so pressing again to undo is an
   obvious thing to do rather than a discovery */
.reaction--mine {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.reaction--busy {
	opacity: .6;
}

.reaction--add {
	padding-inline: 6px;
	color: var(--color-text-maxcontrast);
}

.reaction__emoji {
	font-size: 14px;
	/* an emoji is a picture: it must not pick up the italics or the weight of
	   whatever it is sitting in */
	font-style: normal;
	font-weight: normal;
}

.reaction__count {
	font-variant-numeric: tabular-nums;
}
</style>

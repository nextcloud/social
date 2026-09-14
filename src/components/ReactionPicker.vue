<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- a dialog, not a bare overlay: Escape closes it, the backdrop is
	     labelled and reachable, and focus starts inside it -->
	<div
		v-if="open"
		ref="backdrop"
		class="reaction-picker"
		role="dialog"
		aria-modal="true"
		:aria-label="t('social', 'Choose a reaction')"
		tabindex="-1"
		@click.self="close"
		@keydown.esc.stop="close">
		<div class="reaction-picker__panel">
			<NcEmojiPicker :closeOnSelect="true" container=".reaction-picker__panel" @select="pick">
				<span class="reaction-picker__anchor" />
			</NcEmojiPicker>
			<NcButton
				variant="tertiary"
				:aria-label="t('social', 'Close')"
				:title="t('social', 'Close')"
				@click="close">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmojiPicker from '@nextcloud/vue/components/NcEmojiPicker'
import eventBus, { REACTION_PICK } from '../services/eventBus.js'

/**
 * The emoji picker the reaction bars share.
 *
 * One for the page, mounted by `App` and fetched only once somebody asks for
 * it. The cards do not own it: `@nextcloud/vue` may not be imported anywhere
 * under `TimelinePost`, which the app's entry and the dashboard's both pull
 * in — a framework import beneath it moves the shared l10n chunk out of
 * `social-framework` and copies half a megabyte into each entry.
 *
 * So the card asks, over the bus, and hands a callback with it; this opens,
 * takes the answer, and gives it back. The request stays with the card.
 */
export default {
	name: 'ReactionPicker',

	components: {
		Close,
		NcButton,
		NcEmojiPicker,
	},

	data() {
		return {
			open: false,
			/** what to call with the chosen emoji, null when nothing is open */
			react: null,
		}
	},

	mounted() {
		eventBus.on(REACTION_PICK, this.onAsked)
	},

	beforeUnmount() {
		eventBus.off(REACTION_PICK, this.onAsked)
	},

	methods: {
		t,

		/**
		 * @param {{react: Function}} payload who asked, and what to tell
		 */
		async onAsked(payload) {
			this.react = typeof payload?.react === 'function' ? payload.react : null
			this.open = this.react !== null

			if (this.open) {
				// or Escape would go to whatever the reader last pressed,
				// which is a card behind the backdrop
				await this.$nextTick()
				this.$refs.backdrop?.focus()
			}
		},

		/**
		 * @param {object|string} chosen what the picker handed over — `select`
		 *        passes the emoji as a string; older versions passed an object
		 *        carrying it as `native`
		 */
		pick(chosen) {
			const emoji = typeof chosen === 'string' ? chosen : (chosen?.native ?? '')
			const react = this.react

			this.close()

			if (emoji !== '' && react !== null) {
				react(emoji)
			}
		},

		close() {
			this.open = false
			this.react = null
		},
	},
}
</script>

<style lang="scss" scoped>
/* a backdrop, so pressing away from the picker closes it */
.reaction-picker {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
	/* the same wash a modal uses, so this sits on the page like one */
	background: var(--color-backdrop, rgba(0, 0, 0, .3));
}

.reaction-picker__panel {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 4px;
	padding: 6px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-raised);
}

/* the picker needs a trigger in its default slot; the panel is already open,
   so the trigger is a point for it to position against rather than a control */
.reaction-picker__anchor {
	display: block;
	width: 1px;
	height: 1px;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="interest-cloud"
		:class="{
			'interest-cloud--frozen': frozen,
			'interest-cloud--dragging': dragTag !== null,
			'interest-cloud--empty': ordered.length === 0,
		}">
		<!-- Nothing to show yet is still a cloud-shaped space, so the reader
		     sees what will grow here rather than a sentence where it will be. -->
		<div v-if="ordered.length === 0" class="interest-cloud__placeholder">
			<span
				v-for="(width, index) in GHOSTS"
				:key="index"
				class="interest-cloud__ghost"
				:style="{ '--ghost-width': width + 'em', '--interest-size': STEP_SIZES[Math.min(index, 5)] + 'rem' }"
				aria-hidden="true" />
			<p class="interest-cloud__empty">
				{{ t('social', 'Your interests appear here as you read') }}
			</p>
		</div>

		<p :id="hintId" class="hidden-visually">
			{{ t('social', 'Arrow keys move between hashtags. Enter opens the options of one. Alt and an arrow key moves it one place.') }}
		</p>

		<TransitionGroup
			tag="ul"
			name="interest-cloud"
			class="interest-cloud__flow"
			:aria-label="t('social', 'Your interests, most important first')"
			:aria-describedby="hintId">
			<li
				v-for="(entry, index) in ordered"
				:key="entry.tag"
				class="interest-cloud__item"
				:class="[`interest-cloud__item--step-${stepOf(index)}`, {
					'interest-cloud__item--pinned': entry.pinned,
					'interest-cloud__item--dragged': entry.tag === dragTag,
					'interest-cloud__item--flash': entry.tag === flashTag,
					'interest-cloud__item--open': entry.tag === openTag,
					'interest-cloud__item--removable': entry.source !== 'followed',
				}]"
				:style="{ '--interest-size': STEP_SIZES[stepOf(index)] + 'rem' }"
				@dragover="onDragOver(entry.tag, $event)"
				@drop="onDrop">
				<NcPopover
					:shown="openTag === entry.tag"
					:triggers="[]"
					placement="bottom-start"
					popupRole="dialog"
					@update:shown="onShown(entry.tag, $event)">
					<template #trigger="{ attrs }">
						<button
							:ref="(el) => setTagRef(entry.tag, el)"
							type="button"
							class="interest-cloud__tag"
							v-bind="attrs"
							:tabindex="index === focusIndex ? 0 : -1"
							:aria-label="accessibleName(entry, index)"
							:title="tooltip(entry, index)"
							draggable="true"
							:data-tag="entry.tag"
							@click="toggle(entry.tag)"
							@focus="focusIndex = index"
							@keydown="onKeydown(entry, index, $event)"
							@dragstart="onDragStart(entry.tag, $event)"
							@dragend="onDragEnd">
							<component
								:is="glyphOf(entry)"
								v-if="glyphOf(entry)"
								class="interest-cloud__glyph"
								:size="16"
								aria-hidden="true" />
							<span class="interest-cloud__hash" aria-hidden="true">#</span>
							<span class="interest-cloud__name">{{ entry.tag }}</span>
							<component
								:is="trendOf(entry)"
								v-if="trendOf(entry)"
								class="interest-cloud__trend"
								:class="`interest-cloud__trend--${entry.trend}`"
								:size="16"
								aria-hidden="true" />
						</button>
					</template>

					<div
						class="interest-cloud__menu"
						role="group"
						:aria-label="t('social', 'Options for #{tag}', { tag: entry.tag })"
						@keydown.esc.stop="close(entry.tag)">
						<div class="interest-cloud__menu-head">
							<span class="interest-cloud__menu-tag">{{ '#' + entry.tag }}</span>
							<span class="interest-cloud__menu-meta">{{ tooltip(entry, index) }}</span>
						</div>
						<ul class="interest-cloud__actions">
							<li>
								<button
									type="button"
									class="interest-cloud__action"
									:disabled="index === 0"
									@click="moveBy(entry, -1)">
									<IconChevronUp :size="20" />
									{{ t('social', 'Higher priority') }}
								</button>
							</li>
							<li>
								<button
									type="button"
									class="interest-cloud__action"
									:disabled="index === ordered.length - 1"
									@click="moveBy(entry, 1)">
									<IconChevronDown :size="20" />
									{{ t('social', 'Lower priority') }}
								</button>
							</li>
							<li>
								<button
									type="button"
									class="interest-cloud__action"
									:disabled="index === 0"
									@click="move(entry, 0)">
									<IconChevronDoubleUp :size="20" />
									{{ t('social', 'Move to top') }}
								</button>
							</li>
							<li>
								<button
									type="button"
									class="interest-cloud__action"
									@click="togglePin(entry)">
									<IconPinOff v-if="entry.pinned" :size="20" />
									<IconPin v-else :size="20" />
									{{ entry.pinned ? t('social', 'Unpin') : t('social', 'Pin') }}
								</button>
							</li>
							<li>
								<router-link
									class="interest-cloud__action"
									:to="{ name: 'tags', params: { tag: entry.tag } }">
									<IconPound :size="20" />
									{{ t('social', 'Open #{tag}', { tag: entry.tag }) }}
								</router-link>
							</li>
							<li v-if="entry.source !== 'followed'">
								<button
									type="button"
									class="interest-cloud__action interest-cloud__action--danger"
									@click="remove(entry)">
									<IconClose :size="20" />
									{{ t('social', 'Remove') }}
								</button>
							</li>
						</ul>
						<p v-if="entry.source === 'followed'" class="interest-cloud__menu-note">
							{{ t('social', 'Unfollow #{tag} to remove it from interests', { tag: entry.tag }) }}
						</p>
					</div>
				</NcPopover>

				<!-- A mouse gets a quicker way than opening the popover. Out of the
				     tab order: the keyboard has Delete on the tag, and a second stop
				     per tag would double the length of the cloud to tab through. -->
				<button
					v-if="entry.source !== 'followed'"
					type="button"
					class="interest-cloud__remove"
					tabindex="-1"
					:aria-label="t('social', 'Remove #{tag}', { tag: entry.tag })"
					:title="t('social', 'Remove #{tag}', { tag: entry.tag })"
					@click="remove(entry)">
					<IconClose :size="14" />
				</button>
			</li>

			<li
				key="__end"
				class="interest-cloud__item interest-cloud__item--end"
				@dragover="onDragOverEnd"
				@drop="onDrop">
				<slot name="end" />
			</li>
		</TransitionGroup>

		<p class="hidden-visually" aria-live="polite">
			{{ announcement }}
		</p>
	</div>
</template>

<script>
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import IconBell from 'vue-material-design-icons/BellOutline.vue'
import IconChevronDoubleUp from 'vue-material-design-icons/ChevronDoubleUp.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconPencil from 'vue-material-design-icons/PencilOutline.vue'
import IconPin from 'vue-material-design-icons/Pin.vue'
import IconPinOff from 'vue-material-design-icons/PinOffOutline.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconTrendDown from 'vue-material-design-icons/ArrowDownThin.vue'
import IconTrendUp from 'vue-material-design-icons/ArrowUpThin.vue'
import logger from '../services/logger.js'
import {
	addInterest,
	moveInterest,
	pinInterest,
	removeInterest,
	unpinInterest,
} from '../services/interests.js'
import { showError, showSuccess, showUndo } from '../services/toast.js'
import { STEP_SIZES, moveTo, sizeStep } from '../utils/interestCloud.js'

/** the widths, in em, of the faint pills an empty cloud is drawn with */
const GHOSTS = [6.5, 4.5, 5.5, 3.5, 5, 4, 6, 3.5, 4.5]

/** how long a tag that was just added or moved into view stays lit */
const FLASH_MS = 1600

let uid = 0

/**
 * The reader's interests as a tag cloud, and every way of changing them.
 *
 * An ordered flow rather than a scatter: the tags read in rank order and wrap
 * like words, so the first thing read is the top interest, a drop lands
 * between two neighbours the reader can see, and the keyboard and a screen
 * reader walk the same order the eye does. Size, weight and colour all step
 * down together with the rank (see utils/interestCloud.js), and the rank is
 * also in every tag's accessible name, so it is never carried by size alone.
 *
 * The cloud owns the writes that are about one tag — move, pin, remove — and
 * hands the state each of them answers to its parent with `update`. It never
 * computes a rank itself, apart from the preview it draws while a drag or a
 * keyboard move is on its way to the server: the server decides where pinned
 * and floating tags end up, and the order it answers is the one drawn.
 */
export default {
	name: 'InterestCloud',

	components: {
		IconBell,
		IconChevronDoubleUp,
		IconChevronDown,
		IconChevronUp,
		IconClose,
		IconPencil,
		IconPin,
		IconPinOff,
		IconPound,
		IconTrendDown,
		IconTrendUp,
		NcPopover,
	},

	props: {
		/** the listed interests, in rank order, as the state answers them */
		interests: {
			type: Array,
			default: () => [],
		},

		/** learning is off: the cloud is drawn dimmed, and still editable */
		frozen: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update'],

	data() {
		return {
			GHOSTS,
			STEP_SIZES,
			hintId: `interest-cloud-hint-${++uid}`,
			/** @type {string[]|null} the order drawn while a move is in flight */
			preview: null,
			/** how many moves are still waiting for the server */
			pending: 0,
			/** @type {string|null} the tag being dragged */
			dragTag: null,
			/** whether the drag ended on the cloud rather than being let go elsewhere */
			dropped: false,
			/** @type {string|null} the tag whose popover is open */
			openTag: null,
			/** the tag that holds the roving tab stop */
			focusIndex: 0,
			/** @type {string[]} tags taken out ahead of the server's answer */
			removing: [],
			/** what the live region last said */
			announcement: '',
			/** @type {string|null} the tag lit up after it was added or moved */
			flashTag: null,
			flashTimer: null,
		}
	},

	computed: {
		/** @return {object[]} the tags as they are drawn, preview included */
		ordered() {
			const listed = this.interests.filter((entry) => !this.removing.includes(entry.tag))
			if (this.preview === null) {
				return listed
			}

			const byTag = new Map(listed.map((entry) => [entry.tag, entry]))

			return this.preview.map((tag) => byTag.get(tag)).filter(Boolean)
		},

		/** @return {object} how a score is written in the reader's locale */
		scoreFormat() {
			try {
				return new Intl.NumberFormat(getCanonicalLocale() || 'en', { maximumFractionDigits: 1, minimumFractionDigits: 1 })
			} catch {
				return new Intl.NumberFormat('en', { maximumFractionDigits: 1, minimumFractionDigits: 1 })
			}
		},
	},

	watch: {
		ordered(list) {
			// a removal or a reset can leave the tab stop past the end
			if (this.focusIndex > list.length - 1) {
				this.focusIndex = Math.max(0, list.length - 1)
			}
		},
	},

	created() {
		/** @type {Map<string, HTMLElement>} the tag buttons, for moving focus */
		this.tagRefs = new Map()
	},

	beforeUnmount() {
		clearTimeout(this.flashTimer)
	},

	methods: {
		t,

		/**
		 * @param {number} index where the tag is drawn
		 * @return {number} its size step, 0–5
		 */
		stepOf(index) {
			return sizeStep(index)
		},

		/**
		 * @param {string} tag the tag a button stands for
		 * @param {HTMLElement|null} el the button, or null once it is gone
		 */
		setTagRef(tag, el) {
			if (el) {
				this.tagRefs.set(tag, el)
			} else {
				this.tagRefs.delete(tag)
			}
		},

		/**
		 * @param {object} entry an interest
		 * @return {string} how its source is said
		 */
		sourceLabel(entry) {
			if (entry.source === 'manual') {
				return t('social', 'added by you')
			}
			if (entry.source === 'followed') {
				return t('social', 'followed')
			}

			return t('social', 'learned')
		},

		/**
		 * @param {object} entry an interest
		 * @param {number} index where it is drawn
		 * @return {string} "Rank 4 · learned · score 12.3"
		 */
		tooltip(entry, index) {
			return t('social', 'Rank {rank} · {source} · score {score}', {
				rank: index + 1,
				source: this.sourceLabel(entry),
				score: this.scoreFormat.format(Number(entry.score ?? 0)),
			})
		},

		/**
		 * What a screen reader says for a tag. The rank is in the name because
		 * the size that shows it is invisible there.
		 *
		 * @param {object} entry an interest
		 * @param {number} index where it is drawn
		 * @return {string} "#photography, priority 3 of 24, learned, pinned"
		 */
		accessibleName(entry, index) {
			const vars = {
				tag: entry.tag,
				rank: index + 1,
				total: this.ordered.length,
				source: this.sourceLabel(entry),
			}

			return entry.pinned
				? t('social', '#{tag}, priority {rank} of {total}, {source}, pinned', vars)
				: t('social', '#{tag}, priority {rank} of {total}, {source}', vars)
		},

		/**
		 * A small leading mark for where a tag came from; learned tags, the
		 * usual case, carry none, which keeps the cloud calm.
		 *
		 * @param {object} entry an interest
		 * @return {string|null} the icon component's name
		 */
		glyphOf(entry) {
			if (entry.pinned) {
				return 'IconPin'
			}
			if (entry.source === 'manual') {
				return 'IconPencil'
			}
			if (entry.source === 'followed') {
				return 'IconBell'
			}

			return null
		},

		/**
		 * @param {object} entry an interest
		 * @return {string|null} the arrow for a learned tag that moved this week
		 */
		trendOf(entry) {
			if (entry.source !== 'learned') {
				return null
			}
			if (entry.trend === 'up') {
				return 'IconTrendUp'
			}
			if (entry.trend === 'down') {
				return 'IconTrendDown'
			}

			return null
		},

		/** @param {string} tag the tag whose popover to open or close */
		toggle(tag) {
			this.openTag = this.openTag === tag ? null : tag
		},

		/**
		 * @param {string} tag the tag whose popover changed
		 * @param {boolean} shown whether it is open now
		 */
		onShown(tag, shown) {
			if (!shown && this.openTag === tag) {
				this.openTag = null
			} else if (shown) {
				this.openTag = tag
			}
		},

		/** @param {string} tag the tag whose popover Escape closed */
		close(tag) {
			if (this.openTag === tag) {
				this.openTag = null
			}
			this.focusTag(tag)
		},

		/**
		 * Puts focus on a tag once it has been drawn where it now is.
		 *
		 * @param {string} tag the tag to focus
		 */
		focusTag(tag) {
			this.$nextTick(() => {
				const index = this.ordered.findIndex((entry) => entry.tag === tag)
				if (index !== -1) {
					this.focusIndex = index
				}
				this.tagRefs.get(tag)?.focus()
			})
		},

		/**
		 * Scrolls a tag into view and lights it for a moment: an added tag
		 * lands where its score puts it, which is usually far down the cloud.
		 *
		 * @param {string} tag the tag to show
		 */
		reveal(tag) {
			this.$nextTick(() => {
				const el = this.tagRefs.get(tag)
				if (!el) {
					return
				}
				const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
				el.scrollIntoView?.({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' })
				clearTimeout(this.flashTimer)
				this.flashTag = tag
				this.flashTimer = setTimeout(() => {
					this.flashTag = null
				}, FLASH_MS)
			})
		},

		/**
		 * The roving tab stop, and the keyboard's way of moving a tag.
		 *
		 * The arrows follow reading order: in a right-to-left page the tag
		 * after this one is to its left.
		 *
		 * @param {object} entry the focused interest
		 * @param {number} index where it is drawn
		 * @param {KeyboardEvent} event the key
		 */
		onKeydown(entry, index, event) {
			const rtl = document.documentElement?.dir === 'rtl'
			const forward = rtl ? 'ArrowLeft' : 'ArrowRight'
			const backward = rtl ? 'ArrowRight' : 'ArrowLeft'
			const last = this.ordered.length - 1

			if (event.altKey && (event.key === forward || event.key === backward)) {
				event.preventDefault()
				this.moveBy(entry, event.key === forward ? 1 : -1)
				return
			}

			let target = null
			if (event.key === forward || event.key === 'ArrowDown') {
				target = Math.min(last, index + 1)
			} else if (event.key === backward || event.key === 'ArrowUp') {
				target = Math.max(0, index - 1)
			} else if (event.key === 'Home') {
				target = 0
			} else if (event.key === 'End') {
				target = last
			} else if ((event.key === 'Delete' || event.key === 'Backspace') && entry.source !== 'followed') {
				event.preventDefault()
				this.remove(entry)
				return
			}

			if (target === null) {
				return
			}
			event.preventDefault()
			this.focusTag(this.ordered[target].tag)
		},

		/**
		 * @param {object} entry the interest to move
		 * @param {number} delta -1 for one place higher, 1 for one lower
		 */
		moveBy(entry, delta) {
			const index = this.ordered.findIndex((item) => item.tag === entry.tag)
			const position = index + delta
			if (index === -1 || position < 0 || position > this.ordered.length - 1) {
				return
			}

			this.move(entry, position)
		},

		/**
		 * Moves a tag to a rank, which pins it there.
		 *
		 * Drawn at once in the new place and then sent; the server's answer
		 * replaces the preview when every move in flight has come back, so a
		 * reader pressing Alt+→ three times sees the tag walk three places
		 * rather than wait for each round trip.
		 *
		 * @param {object} entry the interest to move
		 * @param {number} position the 0-based rank it should hold
		 */
		async move(entry, position) {
			this.openTag = null
			const from = this.ordered.findIndex((item) => item.tag === entry.tag)
			if (from === position) {
				this.focusTag(entry.tag)
				return
			}

			this.preview = moveTo(this.ordered.map((item) => item.tag), entry.tag, position)
			this.focusTag(entry.tag)
			this.pending++
			try {
				const state = await moveInterest(entry.tag, position)
				this.$emit('update', state)
				this.announceRank(entry.tag, state)
			} catch (error) {
				logger.error('Could not move the interest', { error })
				showError(this.said(error, t('social', 'Could not move #{tag}', { tag: entry.tag })))
			} finally {
				this.pending--
				if (this.pending === 0) {
					this.preview = null
					this.focusTag(entry.tag)
				}
			}
		},

		/**
		 * @param {string} tag the tag that moved
		 * @param {object} state what the server answered
		 */
		announceRank(tag, state) {
			const list = Array.isArray(state?.interests) ? state.interests : []
			const index = list.findIndex((item) => item.tag === tag)
			if (index === -1) {
				return
			}

			this.announcement = t('social', '#{tag}, now priority {rank} of {total}', {
				tag,
				rank: index + 1,
				total: list.length,
			})
		},

		/** @param {object} entry the interest to pin or unpin */
		async togglePin(entry) {
			this.openTag = null
			try {
				const state = entry.pinned ? await unpinInterest(entry.tag) : await pinInterest(entry.tag)
				this.$emit('update', state)
				this.announcement = entry.pinned
					? t('social', '#{tag} unpinned', { tag: entry.tag })
					: t('social', '#{tag} pinned', { tag: entry.tag })
				// unpinning lets it float, so it may have gone somewhere else
				this.announceRank(entry.tag, state)
				this.focusTag(entry.tag)
			} catch (error) {
				logger.error('Could not pin the interest', { error })
				showError(this.said(error, t('social', 'Could not change #{tag}', { tag: entry.tag })))
			}
		},

		/**
		 * Removes a tag, with an Undo when there is one to offer.
		 *
		 * A manual tag can be put back exactly — added again, and pinned back
		 * at its rank if it was pinned. A learned one cannot: removing it sets
		 * its score to nought, and nothing in the API sets a score, so adding
		 * it back would turn it into a manual tag it never was. It is said to
		 * be gone instead, and that reading can bring it back.
		 *
		 * @param {object} entry the interest to remove
		 */
		async remove(entry) {
			if (entry.source === 'followed' || this.removing.includes(entry.tag)) {
				return
			}

			this.openTag = null
			const index = this.ordered.findIndex((item) => item.tag === entry.tag)
			const next = this.ordered[index + 1] ?? this.ordered[index - 1] ?? null
			this.removing = [...this.removing, entry.tag]

			try {
				const state = await removeInterest(entry.tag)
				this.$emit('update', state)
				this.announcement = t('social', '#{tag} removed from your interests', { tag: entry.tag })
				if (entry.source === 'manual') {
					showUndo(t('social', '#{tag} removed from your interests', { tag: entry.tag }), () => this.undoRemove(entry))
				} else {
					showSuccess(t('social', '#{tag} removed. Reading more of it can bring it back.', { tag: entry.tag }))
				}
				if (next) {
					this.focusTag(next.tag)
				}
			} catch (error) {
				logger.error('Could not remove the interest', { error })
				showError(this.said(error, t('social', 'Could not remove #{tag}', { tag: entry.tag })))
			} finally {
				this.removing = this.removing.filter((tag) => tag !== entry.tag)
			}
		},

		/** @param {object} entry the manual interest as it was before it was removed */
		async undoRemove(entry) {
			try {
				let state = await addInterest(entry.tag)
				if (entry.pinned) {
					state = await moveInterest(entry.tag, entry.rank)
				}
				this.$emit('update', state)
				this.reveal(entry.tag)
			} catch (error) {
				logger.error('Could not put the interest back', { error })
				showError(this.said(error, t('social', 'Could not put #{tag} back', { tag: entry.tag })))
			}
		},

		/**
		 * @param {string} tag the tag picked up
		 * @param {DragEvent} event the drag
		 */
		onDragStart(tag, event) {
			this.openTag = null
			this.dragTag = tag
			this.dropped = false
			this.preview = this.ordered.map((item) => item.tag)
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
				event.dataTransfer.setData?.('text/plain', '#' + tag)
			}
		},

		/**
		 * Makes room for the dragged tag in front of or behind the one it is
		 * over, by which half of that tag the pointer is in. The rest of the
		 * cloud moves out of the way as it goes, so the drop lands where the
		 * gap already is.
		 *
		 * @param {string} tag the tag under the pointer
		 * @param {DragEvent} event the drag
		 */
		onDragOver(tag, event) {
			if (this.dragTag === null) {
				return
			}
			event.preventDefault()
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'move'
			}
			if (tag === this.dragTag) {
				return
			}

			const rect = event.currentTarget?.getBoundingClientRect?.()
			const rtl = document.documentElement?.dir === 'rtl'
			const middle = rect ? rect.left + rect.width / 2 : 0
			const before = rtl ? event.clientX > middle : event.clientX < middle
			const rest = this.preview.filter((item) => item !== this.dragTag)
			const at = rest.indexOf(tag) + (before ? 0 : 1)
			const order = moveTo(this.preview, this.dragTag, at)
			if (order.join('\n') !== this.preview.join('\n')) {
				this.preview = order
			}
		},

		/** @param {DragEvent} event a drag over the space after the last tag */
		onDragOverEnd(event) {
			if (this.dragTag === null) {
				return
			}
			event.preventDefault()
			this.preview = moveTo(this.preview, this.dragTag, this.preview.length)
		},

		/** @param {DragEvent} event the drop */
		onDrop(event) {
			if (this.dragTag === null) {
				return
			}
			event.preventDefault()
			this.dropped = true
			const tag = this.dragTag
			const position = this.preview.indexOf(tag)
			const from = this.interests.findIndex((item) => item.tag === tag)
			this.dragTag = null
			if (position === from) {
				this.preview = null
				return
			}

			const entry = this.interests.find((item) => item.tag === tag)
			this.preview = null
			this.move(entry, position)
		},

		/** A drag let go anywhere but on the cloud changes nothing. */
		onDragEnd() {
			if (!this.dropped) {
				this.preview = null
			}
			this.dragTag = null
		},

		/**
		 * @param {object} error what axios threw
		 * @param {string} fallback what to say when the server said nothing
		 * @return {string} the message
		 */
		said(error, fallback) {
			const message = error?.response?.data?.error

			return typeof message === 'string' && message !== '' ? message : fallback
		},
	},
}
</script>

<style scoped lang="scss">
@use 'sass:map';

/**
 * Six steps, one per size. Colour and weight step down with the size: the
 * top of the cloud is bold on the primary colour, the middle is the text
 * colour, the tail is regular on the muted one. The mixes are of Nextcloud's
 * own variables, so both themes and every accent colour are covered.
 */
$steps: (
	0: (color: var(--color-primary-element), weight: 700, tint: 13%),
	1: (color: color-mix(in srgb, var(--color-primary-element) 80%, var(--color-main-text)), weight: 700, tint: 8%),
	2: (color: color-mix(in srgb, var(--color-primary-element) 55%, var(--color-main-text)), weight: 600, tint: 4%),
	3: (color: var(--color-main-text), weight: 600, tint: 0%),
	4: (color: color-mix(in srgb, var(--color-main-text) 80%, var(--color-text-maxcontrast)), weight: 500, tint: 0%),
	5: (color: var(--color-text-maxcontrast), weight: 400, tint: 0%),
);

.interest-cloud {
	position: relative;
	padding: calc(var(--default-grid-baseline) * 5) calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, var(--border-radius-large));
	// the faintest wash of the accent, so the cloud reads as one object on
	// the section rather than as loose words
	background: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));

	&__flow {
		display: flex;
		flex-wrap: wrap;
		// on one baseline, like words in a line of type, whatever their size
		align-items: baseline;
		gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 1.5);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__item {
		position: relative;
		display: inline-flex;
		align-items: baseline;
		max-width: 100%;
		font-size: var(--interest-size, 1rem);

		// a phone is too narrow for the top step: #astrophotography at 2rem
		// broke in the middle of the word
		@media (max-width: 600px) {
			font-size: calc(var(--interest-size, 1rem) * .75);
		}

		@each $step, $look in $steps {
			&--step-#{$step} {
				--interest-colour: #{map.get($look, color)};
				--interest-weight: #{map.get($look, weight)};
				--interest-tint: color-mix(in srgb, var(--color-primary-element) #{map.get($look, tint)}, transparent);
			}
		}

		// the Add pill sits in the flow like one more tag, at text size
		&--end {
			font-size: 1rem;
			align-self: center;
		}
	}

	// the popover's wrapper would otherwise be a block and break the baseline
	&__item :deep(.v-popper) {
		display: inline-flex;
		max-width: 100%;
	}

	// Nextcloud styles bare buttons, hover and focus included, with rules one
	// class does not outrank; the cloud's own class in front of each of these
	// does
	& &__tag {
		display: inline-flex;
		align-items: baseline;
		gap: .12em;
		max-width: 100%;
		min-height: 0;
		margin: 0;
		padding: .08em .42em .12em;
		border: 1px solid transparent;
		border-radius: var(--border-radius-pill, 999px);
		background: var(--interest-tint);
		color: var(--interest-colour);
		font-size: inherit;
		font-weight: var(--interest-weight);
		line-height: 1.2;
		letter-spacing: -.01em;
		cursor: grab;
		// the name is the one thing that is ever too long, and it breaks
		// rather than pushing the cloud wider than a phone
		overflow-wrap: anywhere;
		text-align: start;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	// Nextcloud's hover, focus and pressed rules for bare buttons are four
	// pseudo-classes deep and paint a dark border and a white background;
	// the item's class in the middle outranks them
	& &__item &__tag {
		&:focus {
			border-color: transparent;
		}

		&:hover {
			background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-background-hover));
			border-color: color-mix(in srgb, var(--color-primary-element) 25%, transparent);
		}

		&:active {
			background: color-mix(in srgb, var(--color-primary-element) 14%, var(--color-background-hover));
			color: var(--interest-colour);
			cursor: grabbing;
		}
	}

	// the states below win over hover and focus, at the same weight and later
	& &__item#{&}__item--open &__tag {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		border-color: var(--color-primary-element);
	}

	&__hash {
		opacity: .45;
		font-weight: 400;
		margin-inline-end: .02em;
	}

	&__name {
		min-width: 0;
	}

	&__glyph,
	&__trend {
		display: inline-flex;
		align-self: center;
		opacity: .75;

		:deep(svg) {
			width: max(.62em, 12px);
			height: max(.62em, 12px);
		}
	}

	&__glyph {
		margin-inline-end: .1em;
	}

	&__trend {
		margin-inline-start: .06em;
		color: var(--color-text-maxcontrast);
		opacity: .9;

		&--up {
			color: var(--color-success-text, var(--color-text-maxcontrast));
		}
	}

	// small and at the corner, so showing it moves nothing else
	& &__remove {
		position: absolute;
		inset-block-start: -7px;
		inset-inline-end: -7px;
		z-index: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 20px;
		block-size: 20px;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: 1px solid var(--color-border-dark, var(--color-border));
		border-radius: 50%;
		background: var(--color-main-background);
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		cursor: pointer;
		opacity: 0;
		pointer-events: none;

	}

	& &__item &__remove:hover,
	& &__item &__remove:focus {
		background: var(--color-error);
		border-color: var(--color-error);
		color: var(--color-primary-element-text, var(--color-main-background));
	}

	&__item:hover &__remove,
	&__item:focus-within &__remove {
		opacity: 1;
		pointer-events: auto;
	}

	&--dragging &__item &__remove {
		opacity: 0;
		pointer-events: none;
	}

	// the one being carried leaves an outline of itself where it will land
	& &__item#{&}__item--dragged &__tag {
		border-style: dashed;
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
		opacity: .55;
	}

	& &__item#{&}__item--flash &__tag {
		background: var(--color-primary-element-light);
		border-color: var(--color-primary-element);
	}

	// learning is off: the cloud is still the reader's to change, so it is
	// dimmed, not disabled, and comes back to full strength under the pointer
	&--frozen &__item:not(&__item--end) {
		filter: grayscale(.85);
		opacity: .6;
	}

	&--frozen &__item:not(&__item--end):hover,
	&--frozen &__item:not(&__item--end):focus-within {
		opacity: .9;
	}

	&__placeholder {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: center;
		gap: 10px 8px;
		padding-block: 4px 12px;
	}

	&__ghost {
		display: inline-block;
		inline-size: var(--ghost-width);
		block-size: 1.3em;
		font-size: var(--interest-size, 1rem);
		border-radius: var(--border-radius-pill, 999px);
		background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
	}

	&__empty {
		flex: 1 0 100%;
		margin: 6px 0 0;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&--empty &__flow {
		justify-content: center;
	}
}

// touch has no hover to reveal the ×, so it is always there, with room made
// for it so it does not sit on the last letter of a small tag
@media (hover: none) {
	.interest-cloud .interest-cloud__remove {
		opacity: 1;
		pointer-events: auto;
	}

	.interest-cloud .interest-cloud__item--removable {
		padding-inline-end: 8px;
	}
}

.interest-cloud__menu {
	min-width: 220px;
	max-width: min(320px, 90vw);
	padding: 4px;

	&-head {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 8px 12px 6px;
		border-block-end: 1px solid var(--color-border);
		margin-block-end: 4px;
	}

	&-tag {
		font-weight: bold;
		overflow-wrap: anywhere;
	}

	&-meta,
	&-note {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&-note {
		margin: 4px 0 0;
		padding: 6px 12px 8px;
		border-block-start: 1px solid var(--color-border);
	}
}

.interest-cloud__actions {
	margin: 0;
	padding: 0;
	list-style: none;
}

.interest-cloud__menu .interest-cloud__action {
	display: flex;
	align-items: center;
	gap: 10px;
	inline-size: 100%;
	min-height: var(--default-clickable-area, 34px);
	margin: 0;
	padding: 0 12px 0 8px;
	border: none;
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	text-decoration: none;
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		background: var(--color-background-hover);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -2px;
	}

	&:disabled {
		opacity: .5;
		cursor: default;
	}

	&--danger {
		color: var(--color-error-text, var(--color-error));
	}
}

@keyframes interest-flash {
	0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-primary-element) 55%, transparent); }
	100% { box-shadow: 0 0 0 10px color-mix(in srgb, var(--color-primary-element) 0%, transparent); }
}

/**
 * The FLIP part is Vue's: TransitionGroup measures every tag before and after
 * a reorder and slides each from where it was with `-move`. The size change
 * rides along on `font-size`, so a tag dropped higher up grows as it lands.
 * About a fifth of a second, which reads as the cloud making room rather
 * than as an animation to watch.
 */
@media (prefers-reduced-motion: no-preference) {
	.interest-cloud__item {
		transition: font-size .2s ease, opacity .2s ease, filter .2s ease;
	}

	.interest-cloud__tag,
	.interest-cloud__remove {
		transition: background-color .15s ease, border-color .15s ease, opacity .15s ease;
	}

	.interest-cloud-move {
		transition: transform .2s ease, font-size .2s ease;
	}

	.interest-cloud-enter-active,
	.interest-cloud-leave-active {
		transition: opacity .2s ease, transform .2s ease;
	}

	.interest-cloud-enter-from,
	.interest-cloud-leave-to {
		opacity: 0;
		transform: scale(.85);
	}

	// out of the flow while it fades, so the others close up at once
	.interest-cloud-leave-active {
		position: absolute;
	}

	.interest-cloud__item--flash .interest-cloud__tag {
		animation: interest-flash .8s ease-out 2;
	}
}

@media (prefers-reduced-motion: reduce) {
	.interest-cloud__item,
	.interest-cloud__tag,
	.interest-cloud__remove,
	.interest-cloud-move,
	.interest-cloud-enter-active,
	.interest-cloud-leave-active {
		transition: none;
	}

	.interest-cloud__item--flash .interest-cloud__tag {
		animation: none;
	}
}
</style>

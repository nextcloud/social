<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<component
		:is="element"
		class="timeline-entry"
		:class="{ notification: isNotification, 'with-header': isNotification, 'timeline-entry--reply': depth > 0 }"
		:style="depth > 0 ? { '--thread-depth': Math.min(depth, MAX_INDENT) } : undefined"
		tabindex="-1">
		<div v-if="isNotification" class="notification__header">
			<span class="notification__summary">
				<ActorAvatar :actor="notification.account" :size="24" />
				<Heart v-if="notification.type === 'favourite'" :size="16" />
				<Repeat v-if="notification.type === 'reblog'" :size="16" />
				<AccountPlusOutline v-if="notification.type === 'follow'" :size="16" />
				<AccountQuestion v-if="notification.type === 'follow_request'" :size="16" />
				<At v-if="notification.type === 'mention'" :size="16" />
				<MessageOutline v-if="notification.type === 'status'" :size="16" />
				<MessagePlusOutline v-if="notification.type === 'update'" :size="16" />
				<Poll v-if="notification.type === 'poll'" :size="16" />
				{{ actionSummary }}
			</span>
			<span class="notification__details">
				<router-link
					v-if="!notificationIsAboutAnAccount && notification.status"
					:to="{ name: 'single-post', params: {
						account: item.account.acct,
						id: notification.status.id,
						type: 'single-post',
					} }"
					:data-timestamp="notification.created_at"
					class="post-timestamp"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</router-link>
				<span
					v-else
					class="post-timestamp"
					:data-timestamp="notification.created_at"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</span>
			</span>
		</div>
		<template v-else-if="isBoost">
			<div class="boost">
				<Repeat :size="16" />
				<router-link :to="{ name: 'profile', params: { account: item.account.acct } }">
					<ActorAvatar :actor="item.account" :size="16" :link="false" />
					<span :title="item.account.acct" class="post-author">
						{{ item.account.display_name }}&ensp;
					</span>
				</router-link>
				{{ t('social', 'boosted') }}
			</div>
		</template>
		<UserEntry v-if="isNotification && notificationIsAboutAnAccount" :displayFollowButton="false" :item="item.account" />
		<template v-else>
			<div v-if="entryContent" class="wrapper">
				<TimelineAvatar
					v-if="!isNotification"
					class="entry__avatar"
					:item="entryContent"
					:size="avatarSize" />
				<TimelinePost
					class="entry__content"
					:item="entryContent"
					:type="type" />
			</div>
		</template>
	</component>
</template>

<script>
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import Bell from 'vue-material-design-icons/Bell.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import At from 'vue-material-design-icons/At.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import { translate } from '@nextcloud/l10n'
import TimelinePost from './TimelinePost.vue'
import ActorAvatar from './ActorAvatar.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import { notificationSummary } from '../services/notifications.js'
import { onTick } from '../services/clock.js'
import { isPhone, onPhoneChange } from '../services/phone.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

/** the face's size inside the card, on a phone */
const PHONE_AVATAR = 36

/** how many levels of a conversation are indented before the column runs out */
const MAX_INDENT = 4

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		ActorAvatar,
		TimelineAvatar,
		UserEntry,
		Bell,
		Repeat,
		Heart,
		AccountPlusOutline,
		AccountQuestion,
		At,
		Poll,
		MessageOutline,
		MessagePlusOutline,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status|import('../types/Mastodon.js').Notification>} */
		item: {
			type: Object,
			default: () => {},
		},

		type: {
			type: String,
			required: true,
		},

		/**
		 * How deep this entry sits in a conversation: 0 for a reply to the post
		 * being read, 1 for a reply to that, and so on. Indented accordingly,
		 * up to MAX_INDENT levels — deeper than that the column would run out.
		 */
		depth: {
			type: Number,
			default: 0,
		},

		element: {
			type: String,
			default: 'li',
		},
	},

	data() {
		return {
			/**
			 * On a phone the avatar column beside the card would take a
			 * quarter of the width, so the face moves inside the card and
			 * shrinks; see the `@media` block below.
			 */
			isPhone: isPhone(),
			MAX_INDENT,
			/** re-read from the shared clock, so the wording stays true */
			now: Date.now(),
		}
	},

	computed: {
		/** the face's size: smaller on a phone, where it sits inside the card */
		avatarSize() {
			return this.isPhone ? PHONE_AVATAR : null
		},

		...mapStores(useTimelineStore),
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.isNotification) {
				return this.notification.status
			} else if (this.isBoost) {
				// We use the object stored in the store so that actions on it are reflected.
				return this.timelineStore.getStatus(this.item.reblog.id)
			} else {
				return this.item
			}
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {string} */
		notificationFormattedDate() {
			return fullDateTime(this.notification.created_at)
		},

		/** @return {string} */
		notificationRelativeTimestamp() {
			return fromNow(this.notification.created_at, new Date(this.now))
		},

		/** @return {boolean} */
		isBoost() {
			return this.status.reblog !== null
		},

		/** @return {import('../types/Mastodon.js').Notification} */
		notification() {
			return this.item
		},

		/** @return {import('../types/Mastodon.js').Status} */
		status() {
			return this.item
		},

		/** @return {boolean} */
		notificationIsAboutAnAccount() {
			return ['follow', 'follow_request', 'admin.sign_up', 'admin.report'].includes(this.notification.type)
		},

		/**
		 * @return {string}
		 */
		actionSummary() {
			return notificationSummary(this.notification)
		},
	},

	mounted() {
		this.stopPhoneWatch = onPhoneChange((phone) => {
			this.isPhone = phone
		})
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		this.stopPhoneWatch?.()
		this.stopTicking?.()
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.wrapper {
	display: flex;
	gap: 12px;
	padding: 0;

	&:focus {
		background-color: var(--color-background-hover);
	}

	.entry__avatar {
		flex-shrink: 0;
		margin-top: 6px;
	}

	.entry__content {
		flex-grow: 1;
		min-width: 0;
	}
}

.timeline-entry {
	margin-bottom: 14px;
	padding: 0;
	border-radius: 8px;

	// a reply to a reply steps in under the one it answers, with a line down
	// its side that says so; the depth is the custom property the list sets
	&--reply {
		margin-inline-start: calc(var(--thread-depth, 1) * 24px);
		padding-inline-start: 12px;
		border-inline-start: 2px solid var(--color-border);
		border-radius: 0 8px 8px 0;
	}

	&:last-child {
		margin-bottom: 0;
	}

	// The post inside opens its actions into a panel that reaches over the gap
	// to the next entry, so the entry it belongs to has to be above the entries
	// below it. Its own z-index cannot do that: every entry carries the
	// scroll-driven `timeline-entry-rise` transform, which makes each one a
	// stacking context, and a z-index inside a stacking context cannot lift it
	// past a sibling. Without this the *next* entry's "X boosted" line is drawn
	// straight through the action icons.
	&:hover,
	&:focus-within {
		position: relative;
		z-index: 3;
	}

	// the same while the overflow menu holds a panel open with the pointer
	// somewhere else entirely. Its own rule: a browser without `:has()` drops
	// the selector, and it must not take the hover case with it.
	&:has(.post-actions-reveal--held) {
		position: relative;
		z-index: 3;
	}

	// The panel lands on the gap, and a boosted entry keeps its "X boosted"
	// byline there — which starts further left than the card, so the panel
	// covers all of it but the first few letters and leaves them sticking out
	// like a fault. The line steps out of the way instead: it is about to be
	// covered either way, and half a word is worse than none.
	&:hover + .timeline-entry .boost {
		opacity: 0;
	}

	&:has(.post-actions-reveal--held) + .timeline-entry .boost {
		opacity: 0;
	}

	// A notification is a card of its own: it is a thing that happened, and
	// the post inside it is quoted evidence. A boost is not — it is somebody
	// else's post with a line saying who passed it on, so giving it a card
	// too put a box inside a box and inset the post by the outer padding,
	// leaving boosted posts narrower than every post around them.
	&.with-header {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: 8px;
		padding: 14px;
	}

	&.notification {
		margin-bottom: 10px;
	}
}

.notification {
	&__header {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		padding-bottom: 4px;
	}

	&__summary {
		flex-grow: 1;
		display: flex;
		align-items: center;
		// the badge sits over the face's corner and reaches past it, so the
		// words start clear of the badge rather than under it
		gap: 12px;
		color: var(--color-text-lighter);
		font-size: 13px;
		position: relative;

		.material-design-icon {
			position: absolute;
			top: 12px;
			inset-inline-start: 14px;
			padding: 2px;
			background: var(--color-main-background);
			border-radius: 50%;
			border: 1px solid var(--color-background-dark);
		}
	}

	&__details {
		font-size: 12px;

		.post-timestamp {
			color: var(--color-text-lighter);
		}

		a:hover {
			text-decoration: underline;
		}
	}

	:deep(.post-header) {
		.post-visibility,
		.post-timestamp {
			display: none;
		}
	}

	:deep(.user-entry) {
		.user-avatar {
			display: none;
		}
	}
}

.boost {
	color: var(--color-text-lighter);
	font-size: 13px;
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 6px;
	padding-inline-start: 4px;
	// it fades rather than vanishes when the entry above opens its actions
	// over it; see the rule in `.timeline-entry`
	transition: opacity .16s ease;

	a {
		font-weight: 600;
		color: var(--color-main-text);

		&:hover {
			color: var(--color-primary-element);
		}
	}
}

// the byline still steps out of the panel's way; it just stops fading to do it
@media (prefers-reduced-motion: reduce) {
	.boost {
		transition: none;
	}
}

/*
 * A phone. The avatar column beside the card took 64 of a phone's 390 pixels
 * and gave every post a quarter less room than the screen has, so the face
 * moves inside the card, smaller (`PHONE_AVATAR`), over the corner the header
 * leaves for it. Same number as `PHONE_WIDTH` in services/phone.js.
 */
@media (max-width: 600px) {
	.wrapper {
		position: relative;
		gap: 0;

		.entry__avatar {
			position: absolute;
			top: 12px;
			inset-inline-start: 12px;
			z-index: 2;
			margin-top: 0;
		}

		:deep(.post-header) {
			// the face is 36 wide and sits 12 in; the card's own padding is 16
			padding-inline-start: 36px;
			min-height: 36px;
			align-items: center;
		}
	}
}
</style>

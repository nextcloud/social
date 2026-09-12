<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<AccountHoverCard
		v-if="showHoverCard"
		:handle="actor.acct"
		:fallback="actor"
		variant="block">
		<NcAvatar v-bind="avatarProps" />
	</AccountHoverCard>
	<NcAvatar v-else v-bind="avatarProps" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { generateUrl } from '@nextcloud/router'
import AccountHoverCard from './AccountHoverCard.vue'

export default {
	name: 'ActorAvatar',
	components: {
		AccountHoverCard,
		NcAvatar,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */
		actor: {
			type: Object,
			default: () => {},
		},

		size: {
			type: Number,
			default: 32,
		},

		/**
		 * Whether hovering this avatar previews the account. Off where the
		 * avatar is decoration rather than a reference to somebody — the
		 * reader's own face in the composer, say.
		 */
		hoverCard: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},

	computed: {
		/**
		 * @return {boolean} an actor without a handle cannot be looked up
		 */
		showHoverCard() {
			return this.hoverCard && Boolean(this.actor.acct)
		},

		/**
		 * What NcAvatar is given, in one place so the hovered and the plain
		 * avatar cannot drift apart.
		 *
		 * @return {object}
		 */
		avatarProps() {
			return {
				size: this.size,
				hideStatus: true,
				disableTooltip: true,
				...(this.isLocal
					? { user: this.actor.username, displayName: this.actor.acct }
					: { url: this.avatarUrl }),
			}
		},

		/** @return {string} */
		avatarUrl() {
			// Remote actors are delivered with an avatar URL already pointing at this
			// server's document cache. Actors cached without an icon have none, so fall
			// back to the endpoint that resolves one from the ActivityPub id.
			return this.actor.avatar
				|| generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + encodeURIComponent(this.actor.url ?? ''))
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.actor.acct.includes('@')
		},
	},
}
</script>

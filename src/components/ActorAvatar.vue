<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAvatar v-if="isLocal"
		:size="size"
		:user="actor.username"
		:display-name="actor.acct"
		:disable-tooltip="true"
		:hide-status="true" />
	<NcAvatar v-else
		:size="size"
		:url="avatarUrl"
		:hide-status="true"
		:disable-tooltip="true" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ActorAvatar',
	components: {
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
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
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

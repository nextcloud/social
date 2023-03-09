<!--
  - @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<NcAvatar v-if="isLocal"
		:size="size"
		:user="actor.username"
		:display-name="actor.acct"
		:disable-tooltip="true"
		:show-user-status="false" />
	<NcAvatar v-else
		:size="size"
		:url="avatarUrl"
		:show-user-status="false"
		:disable-tooltip="true" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
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
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
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

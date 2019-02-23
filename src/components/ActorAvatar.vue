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
	<avatar v-if="actor.local" :size="size" :user="actor.preferredUsername"
		:display-name="actor.account" :disable-tooltip="true" />
	<avatar v-else :size="size" :url="avatarUrl"
		:disable-tooltip="true" />
</template>

<script>
import { Avatar } from 'nextcloud-vue'

export default {
	name: 'ActorAvatar',
	components: {
		Avatar
	},
	props: {
		actor: { type: Object, default: () => {} },
		size: { type: Number, default: 32 }
	},
	data: function() {
		return {
			followingText: t('social', 'Following')
		}
	},
	computed: {
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		}
	}
}
</script>

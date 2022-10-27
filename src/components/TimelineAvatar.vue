<template>
	<div v-if="item.actor_info" class="post-avatar">
		<NcAvatar v-if="item.local"
			class="messages__avatar__icon"
			:show-user-status="false"
			menu-position="left"
			:user="userTest"
			:display-name="item.actor_info.account"
			:disable-tooltip="true" />
		<NcAvatar v-else
			:url="avatarUrl"
			:disable-tooltip="true" />
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'

export default {
	name: 'TimelineAvatar',
	components: {
		NcAvatar,
	},
	props: {
		item: {
			type: Object,
			default: () => {},
		},
	},
	computed: {
		userTest() {
			return this.item.actor_info.preferredUsername
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		},
	},
}
</script>

<style scoped>
.post-avatar {
	position: relative;
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;
}
</style>

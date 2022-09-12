<template>
	<div v-if="item.actor_info" class="post-avatar">
		<Avatar v-if="item.local"
			class="messages__avatar__icon"
			:show-user-status="false"
			menu-position="left"
			:user="userTest"
			:display-name="item.actor_info.account"
			:disable-tooltip="true" />
		<Avatar v-else
			:url="avatarUrl"
			:disable-tooltip="true" />
	</div>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'

export default {
	name: 'TimelineAvatar',
	components: {
		Avatar
	},
	props: {
		item: {
			type: Object,
			default: () => {}
		}
	},
	computed: {
		userTest() {
			return this.item.actor_info.preferredUsername
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		}
	}
}
</script>

<style scoped>
.post-avatar {
	position: relative;
	padding: 18px 10px 10px 10px;
	height: 52px;
	width: 52px;
}
</style>

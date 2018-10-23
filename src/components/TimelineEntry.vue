<template>
	<div class="timeline-entry">
		<div class="entry-content">
			<div class="post-avatar">
				<avatar :size="32" :url="item.authorAvatar" />
			</div>
			<div class="post-content">
				<div class="post-author-wrapper">
					<router-link :to="{ name: 'profile', params: { account: item.authorId }}">
						<span class="post-author">{{ item.author }}</span>
						<span class="post-author-id">{{ item.authorId }}</span>
					</router-link>
				</div>
				<div class="post-message" v-html="formatedMessage">
				</div>
			</div>
			<div class="post-timestamp">{{ item.timestamp }}</div>
		</div>
	</div>
</template>

<script>
	import { Avatar } from 'nextcloud-vue'

	export default {
		name: 'TimelineEntry',
		props: ['item'],
		components: {
			Avatar
		},
		data: function () {
			return {

			};
		},
		computed: {
			formatedMessage: function() {
				let message = this.item.message;
				message = message.replace(/(?:\r\n|\r|\n)/g, '<br />');
				return message;
			}
		}
	}
</script>
<style scoped>
	.timeline-entry {
		padding: 10px;
		margin-bottom: 10px;
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.post-author-id {
		opacity: .7;
	}

	.post-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		overflow: hidden;
		width: 32px;
		height: 32px;
		min-width: 32px;
		flex-shrink: 0;
	}

	.timestamp {
		float: right;
	}

	span {
		/* opacity: 0.5; */
	}
	.entry-content {
		display: flex;
	}

	.post-content {
		flex-grow: 1;
	}

	.post-timestamp {
		opacity: .7;
	}
</style>

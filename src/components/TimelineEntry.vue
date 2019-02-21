<template>
	<div class="timeline-entry">
		<div class="entry-content">
			<div v-if="item.actor_info" class="post-avatar">
				<avatar v-if="item.local" :size="32" :user="item.actor_info.preferredUsername"
					:display-name="item.actor_info.account" :disable-tooltip="true" />
				<avatar v-else :size="32" :url="avatarUrl"
					:disable-tooltip="true" />
			</div>
			<div class="post-content">
				<div class="post-author-wrapper">
					<router-link v-if="item.actor_info" :to="{ name: 'profile', params: { account: item.local ? item.actor_info.preferredUsername : item.actor_info.account }}">
						<span class="post-author">
							{{ userDisplayName(item.actor_info) }}
						</span>
						<span class="post-author-id">
							@{{ item.actor_info.account }}
						</span>
					</router-link>
					<a v-else :href="item.attributedTo">
						<span class="post-author-id">
							{{ item.attributedTo }}
						</span>
					</a>
				</div>
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div class="post-message" v-html="formatedMessage" />
			</div>
			<div :data-timestamp="timestamp" class="post-timestamp live-relative-timestamp">
				{{ relativeTimestamp }}
			</div>
		</div>
	</div>
</template>

<script>
import { Avatar } from 'nextcloud-vue'
import * as linkify from 'linkifyjs'
import pluginTag from 'linkifyjs/plugins/hashtag'
import pluginMention from 'linkifyjs/plugins/mention'
import 'linkifyjs/string'

pluginTag(linkify)
pluginMention(linkify)

export default {
	name: 'TimelineEntry',
	components: {
		Avatar
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	data() {
		return {

		}
	},
	computed: {
		relativeTimestamp() {
			return OC.Util.relativeModifiedDate(this.item.published)
		},
		timestamp() {
			return Date.parse(this.item.published)
		},
		formatedMessage() {
			let message = this.item.content
			message = message.replace(/(?:\r\n|\r|\n)/g, '<br />')
			message = message.linkify({
				formatHref: {
					hashtag: function(href) {
						return OC.generateUrl('/apps/social/timeline/tags/' + href.substring(1))
					},
					mention: function(href) {
						return OC.generateUrl('/apps/social/@' + href.substring(1))
					}
				}
			})
			message = this.$twemoji.parse(message)
			return message
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		}
	},
	methods: {
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
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

	.post-author {
		font-weight: bold;
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

	.post-timestamp {
		width: 120px;
		text-align: right;
		flex-shrink: 0;
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
<style>
	.post-message a {
		text-decoration: underline;
	}
</style>

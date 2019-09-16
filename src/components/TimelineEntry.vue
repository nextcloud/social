<template>
	<div class="timeline-entry" @click="getSinglePostTimeline">
		<div v-if="item.type === 'SocialAppNotification'" class="notification-header">
			<div class="notification-icon" :class="notificationIcon" />
			<span class="notification-action">
				{{ actionSummary }}
			</span>
		</div>
		<div v-if="item.type === 'Announce'" class="boost">
			<div class="container-icon-boost">
				<span class="icon-boost icon-boosting" />
			</div>
			<router-link v-if="item.actor_info" :to="{ name: 'profile', params: { account: item.local ? item.actor_info.preferredUsername : item.actor_info.account }}">
				<span v-tooltip.bottom="item.actor_info.account" class="post-author">
					{{ userDisplayName(item.actor_info) }}
				</span>
			</router-link>
			<a v-else :href="item.attributedTo">
				<span class="post-author-id">
					{{ item.attributedTo }}
				</span>
			</a>
			{{ boosted }}
		</div>
		<user-entry v-if="item.type === 'SocialAppNotification' && item.details.actor" :key="item.details.actor.id" :item="item.details.actor" />
		<timeline-post
			v-else-if="item.type === 'SocialAppNotification' && item.details.post"
			:item="item.details.post" />
		<timeline-post
			v-else
			:item="entryContent"
			:parent-announce="isBoost" />
	</div>
</template>

<script>
import TimelinePost from './TimelinePost.vue'
import UserEntry from './UserEntry.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		UserEntry
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	data() {
		return {
		}
	},
	computed: {
		entryContent() {
			if (this.item.type === 'Announce') {
				return this.item.cache[this.item.object].object
			} else {
				return this.item
			}
		},
		isBoost() {
			if (this.item.type === 'Announce') {
				return this.item
			}
			return {}
		},
		boosted() {
			return t('social', 'boosted')
		},
		actionSummary() {

			let summary = this.item.summary
			for (var key in this.item.details) {

				let keyword = '{' + key + '}'
				if (typeof this.item.details[key] !== 'string' && this.item.details[key].length > 1) {

					let concatination = ''
					for (var stringKey in this.item.details[key]) {

						if (this.item.details[key].length > 3 && stringKey === '3') {
							// ellipses the actors' list to 3 actors when it's big
							concatination = concatination.substring(0, concatination.length - 2)
							concatination += ' and ' + (this.item.details[key].length - 3).toString() + ' other(s), '
							break
						} else {
							concatination += this.item.details[key][stringKey] + ', '
						}
					}

					concatination = concatination.substring(0, concatination.length - 2)
					summary = summary.replace(keyword, concatination)

				} else {
					summary = summary.replace(keyword, this.item.details[key])
				}
			}

			return summary
		},
		notificationIcon() {
			switch (this.item.subtype) {
			case 'Like':
				return 'icon-favorite'
			case 'Announce':
				return 'icon-boost'
			case 'Follow':
				return 'icon-user'
			default:
				return ''
			}
		}
	},
	methods: {
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		}
	}
}
</script>
<style scoped lang="scss">
	.timeline-entry {
		padding: 10px;
		margin-bottom: 10px;
		&:hover {
			background-color: var(--color-background-hover);
		}
	}

	.notification-header {
		display: flex;
		align-items: bottom;
	}

	.notification-action {
		flex-grow: 1;
		display: inline-block;
		align-self: flex-end;
	}

	.notification-icon {
		margin: 5px 10px 0 5px;
		opacity: .5;
		background-position: center;
		background-size: contain;
		overflow: hidden;
		height: 20px;
		min-width: 32px;
		flex-shrink: 0;
	}

	.icon-boost {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-favorite {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-user {
		display: inline-block;
		vertical-align: middle;
	}

	.container-icon-boost {
		display: inline-block;
		padding-right: 6px;
	}

	.icon-boosting {
		width: 38px;
		height: 17px;
		background-position: right center;
	}

	.boost {
		opacity: .5;
	}
</style>

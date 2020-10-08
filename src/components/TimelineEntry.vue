<template>
	<div :class="['timeline-entry', hasHeader ? 'with-header' : '']" @click="getSinglePostTimeline">
		<template v-if="item.type === 'SocialAppNotification'">
			<div class="notification-icon" :class="notificationIcon" />
			<span class="notification-action">
				{{ actionSummary }}
			</span>
		</template>
		<template v-else-if="item.type === 'Announce'">
			<div class="container-icon-boost boost">
				<span class="icon-boost" />
			</div>
			<div class="boost">
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
		</template>
		<user-entry v-if="item.type === 'SocialAppNotification' && item.details.actor" :key="item.details.actor.id" :item="item.details.actor" />
		<template v-else>
			<timeline-avatar :item="entryContent" />
			<timeline-post
				:item="entryContent"
				:parent-announce="isBoost" />
		</template>
	</div>
</template>

<script>
import TimelinePost from './TimelinePost.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		TimelineAvatar,
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
			} else if (this.item.type === 'SocialAppNotification') {
				return this.item.details.post
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
		hasHeader() {
			return this.item.type === 'Announce' || this.item.type === 'SocialAppNotification'
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
  .timeline-entry.with-header {
    grid-template-rows: 30px 1fr;
  }
	.timeline-entry {
		display: grid;
    grid-template-columns: 44px 1fr;
    grid-template-rows: 1fr;
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
		grid-row: 1;
		grid-column: 2;
	}

	.notification-icon {
		opacity: .5;
		background-position: center;
		background-size: contain;
		overflow: hidden;
		height: 20px;
		min-width: 32px;
		flex-shrink: 0;
		display: inline-block;
		vertical-align: middle;
		grid-column: 1;
		grid-row: 1;
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

	.icon-boost {
		display: inline-block;
		width: 38px;
		height: 17px;
		opacity: .5;
		background-position: right center;
		vertical-align: middle;
	}

	.boost {
		opacity: .5;
	}
</style>

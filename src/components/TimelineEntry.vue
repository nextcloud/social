<template>
	<div :class="['timeline-entry', hasHeader ? 'with-header' : '']">
		<div v-if="item.type === 'SocialAppNotification'" class="notification">
			<Bell :size="22" />
			<span class="notification-action">
				{{ actionSummary }}
			</span>
		</div>
		<template v-else-if="item.type === 'Announce'">
			<div class="container-icon-boost boost">
				<span class="icon-boost" />
			</div>
			<div class="boost">
				<router-link v-if="!isProfilePage && item.actor_info" :to="{ name: 'profile', params: { account: item.local ? item.actor_info.preferredUsername : item.actor_info.account }}">
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
		<UserEntry v-if="item.type === 'SocialAppNotification' && item.details.actor" :key="item.details.actor.id" :item="item.details.actor" />
		<template v-else>
			<div class="wrapper">
				<TimelineAvatar :item="entryContent" />
				<TimelinePost class="message"
					:item="entryContent"
					:parent-announce="isBoost" />
			</div>
		</template>
	</div>
</template>

<script>
import TimelinePost from './TimelinePost.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import Bell from 'vue-material-design-icons/Bell.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		TimelineAvatar,
		UserEntry,
		Bell,
	},
	props: {
		item: {
			type: Object,
			default: () => {},
		},
		isProfilePage: {
			type: Boolean,
			default: false,
		},
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
			for (const key in this.item.details) {

				const keyword = '{' + key + '}'
				if (typeof this.item.details[key] !== 'string' && this.item.details[key].length > 1) {

					let concatination = ''
					for (const stringKey in this.item.details[key]) {

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
	},
	methods: {
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},
	},
}
</script>
<style scoped lang="scss">
	.wrapper {
		display: flex;
		margin: auto;
		padding: 0;
		&:focus {
			background-color: rgba(47, 47, 47, 0.068);
		}
	}

	.notification-header {
		display: flex;
		align-items: bottom;
	}

	.notification {
		display: flex;
		padding-left: 2rem;
		gap: 0.2rem;
		margin-top: 1rem;

		&-action {
			flex-grow: 1;
			display: inline-block;
			grid-row: 1;
			grid-column: 2;
			color: var(--color-text-lighter);
		}

		.bell-icon {
			opacity: .5;
		}
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

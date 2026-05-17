<template>
	<div v-if="profileAccount && accountInfo" class="user-profile">
		<NcAvatar v-if="isLocal"
			:user="localUid"
			:disable-tooltip="true"
			:size="128" />
		<NcAvatar v-else
			:url="accountInfo.avatar"
			:disable-tooltip="true"
			:size="128" />
		<h2>{{ displayName }}</h2>
		<ul class="user-profile__info user-profile__sections">
			<li>
				<router-link :to="{ name: 'profile', params: { account: uid } }">
					{{ accountInfo.statuses_count }} {{ t('social', 'posts') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.following', params: { account: uid } }">
					{{ accountInfo.following_count }}  {{ t('social', 'following') }}
				</router-link>
			</li>
			<li>
				<router-link :to="{ name: 'profile.followers', params: { account: uid } }">
					{{ accountInfo.followers_count }}  {{ t('social', 'followers') }}
				</router-link>
			</li>
		</ul>
		<div class="user-profile__actions">
			<FollowButton :uid="uid" />
			<NcButton v-if="serverData.public"
				type="primary"
				@click="followRemote">
				{{ t('social', 'Follow') }}
			</NcButton>
		</div>
		<MessageContent v-if="accountInfo.note" class="user-profile__note" :item="{content: accountInfo.note, tag: [], mentions: []}" />
	</div>
</template>

<script>
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import currentUser from '../mixins/currentUserMixin.js'
import FollowButton from './FollowButton.vue'
import MessageContent from './MessageContent.js'

export default {
	name: 'ProfileInfo',
	components: {
		FollowButton,
		NcAvatar,
		NcButton,
		OpenInNew,
		MessageContent,
	},
	mixins: [
		accountMixins,
		currentUser,
		serverData,
	],
	props: {
		uid: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
		localUid() {
			return (this.uid.indexOf('@') === -1) ? this.uid : this.uid.slice(0, this.uid.indexOf('@'))
		},
		displayName() {
			return this.accountInfo.display_name ?? this.accountInfo.username ?? this.profileAccount
		},
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		},
		website() {
			return this.accountInfo.fields.find(field => field.name === 'Website')
		},
	},
	methods: {
		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		},

		t: translate,
	},
}
</script>

<style scoped lang="scss">
.user-profile {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	max-width: 600px;
	margin: 0 auto calc(var(--default-grid-baseline) * 6);
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 4);
	text-align: center;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 16px;
	box-shadow: none;

	h2 {
		margin-top: 16px;
		font-size: 26px;
		font-weight: 700;
		letter-spacing: -.02em;
	}

	&__info {
		margin-bottom: 14px;
		display: flex;
		gap: 20px;
		justify-content: center;
		color: var(--color-text-lighter);

		a {
			display: flex;
			align-items: center;
			gap: 4px;
			font-size: 13px;
			color: var(--color-text-lighter);
			opacity: .75;
			transition: all .15s ease;

			&:hover {
				opacity: 1;
				color: var(--color-primary-element);
				text-decoration: none;
			}
		}
	}

	&__actions {
		display: flex;
		gap: 10px;
		margin-top: 12px;
	}

	&__note {
		text-align: start;
		width: 100%;
		margin-top: 18px;
		padding-top: 18px;
		border-top: 1px solid var(--color-border);
		font-size: 14px;
		line-height: 1.7;
		overflow-wrap: break-word;
		word-wrap: break-word;
		word-break: break-word;
		hyphens: auto;

		p {
			margin: 0 0 8px;
			&:last-child {
				margin-bottom: 0;
			}
		}

		a {
			color: var(--color-primary-element);
			text-decoration: underline;
		}
	}

	&__sections {
		display: flex;
		gap: 24px;
		margin: 14px 0;

		li {
			a {
				padding: 8px 12px;
				font-size: 14px;
				font-weight: 600;
				border-radius: 8px;
				opacity: .65;
				transition: all .15s ease;

				&.router-link-exact-active,
				&:focus {
					opacity: 1;
					background: var(--color-background-hover);
				}

				&.disabled {
					opacity: 1;
					text-decoration: none;
					cursor: auto;
					pointer-events: none;
				}
			}
		}
	}
}
</style>

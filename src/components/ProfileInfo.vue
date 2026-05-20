<template>
	<div v-if="profileAccount && accountInfo" class="user-profile">
		<label v-if="isOwnProfile" class="user-profile__banner-upload">
			<input type="file" accept="image/*" @change="uploadBanner">
			{{ t('social', 'Change banner') }}
		</label>
		<div class="user-profile__banner" :style="headerStyle"></div>
		<div class="user-profile__content">
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
	</div>
</template>

<script>
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
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
			bannerUrl: null,
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
		isOwnProfile() {
			return this.currentUser?.uid && this.localUid === this.currentUser.uid
		},
		headerStyle() {
			const url = this.bannerUrl || this.accountInfo.header
			if (url) {
				return { backgroundImage: `url(${url})` }
			}
			return {}
		},
	},
	methods: {
		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		},
		async uploadBanner(event) {
			const file = event.target.files[0]
			if (!file) return

			const formData = new FormData()
			formData.append('file', file)

			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/banner'),
					formData,
					{ headers: { 'Content-Type': 'multipart/form-data' } }
				)
				this.bannerUrl = data.result.url
				showSuccess(t('social', 'Banner uploaded successfully'))
			} catch (error) {
				showError(t('social', 'Failed to upload banner'))
			}
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
	text-align: center;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
	position: relative;

	&__banner {
		min-height: 120px;
		max-height: 200px;
		background-size: cover;
		background-position: center 0%;
		background-repeat: no-repeat;
		background-color: var(--color-background-dark);
	}

	&__banner-upload {
		position: absolute;
		top: 12px;
		right: 12px;
		z-index: 10;
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 6px 14px;
		background: rgba(0, 0, 0, 0.5);
		color: #fff;
		border-radius: 6px;
		cursor: pointer;
		font-size: 13px;
		opacity: 0;
		transition: opacity 0.2s;

		input {
			display: none;
		}

		&:hover,
		&:focus-within {
			opacity: 1;
		}
	}

	&:hover &__banner-upload {
		opacity: 1;
	}

	&__content {
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		padding: 56px calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4);
		background: var(--color-main-background);
		position: relative;
		z-index: 1;

		:deep(.avatardiv) {
			position: absolute;
			top: -48px;
		}
	}

	h2 {
		margin-top: 28px;
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

			&:hover {
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

				&.router-link-exact-active,
				&:focus {
					background: var(--color-background-hover);
				}

				&.disabled {
					text-decoration: none;
					cursor: auto;
					pointer-events: none;
				}
			}
		}
	}
}
</style>

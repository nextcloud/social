<template>
	<div v-if="profileAccount && accountInfo" class="user-profile">
		<NcButton v-if="isOwnProfile" class="user-profile__banner-upload" :disabled="loading" @click="openFilePicker">
			<template #icon>
				<ImagePlus :size="20" />
			</template>
			{{ loading ? t('social', 'Uploading…') : t('social', 'Change banner') }}
		</NcButton>
		<div
			class="user-profile__banner"
			:class="{ 'user-profile__banner--editable': isOwnProfile }"
			:style="headerStyle"
			@click="isOwnProfile ? openFilePicker() : undefined"
		></div>
		<!-- hidden fallback input for environments where the dialogs picker API is incompatible -->
		<input ref="bannerInput" type="file" accept="image/*" style="display:none" @change="uploadBanner" />
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
import ImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
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
		ImagePlus,
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
			loading: false,
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
		async openFilePicker() {
			if (this.$refs.bannerInput) {
				this.$refs.bannerInput.click()
			}
		},

		async uploadBanner(event) {
			const file = event?.target?.files?.[0]
			if (!file) return
			this.loading = true
			try {
				const formData = new FormData()
				formData.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/banner'),
					formData
				)
				this.bannerUrl = data.result.url
				await this.showSuccess(t('social', 'Banner uploaded successfully'))
			} catch (error) {
				await this.showError(t('social', 'Failed to upload banner'))
			} finally {
				this.loading = false
				// reset input so same file can be selected again if needed
				if (event && event.target) event.target.value = ''
			}
		},

		async uploadBannerFromPath(path) {
			this.loading = true
			try {
				// Normalise builder return types (string or object)
				let filePath = path
				if (filePath && typeof filePath === 'object') {
					filePath = filePath.path || filePath.value || filePath.fullPath || filePath.name || filePath[0] || null
				}
				const downloadCandidates = []
				if (!filePath) {
					if (path && typeof path === 'object') {
						downloadCandidates.push(path.url, path.downloadUrl, path.href)
					}
				} else {
					downloadCandidates.push(
						generateRemoteUrl('dav/files/' + encodeURIComponent(this.currentUser.uid) + filePath)
					)
				}
				let blob = null
				for (const candidate of downloadCandidates) {
					if (!candidate) continue
					try {
						const resp = await axios.get(candidate, { responseType: 'blob' })
						blob = resp.data
						break
					} catch (e) {
						// try next candidate
						continue
					}
				}
				if (!blob) throw new Error('Failed to download file for upload')
				const filename = (filePath && filePath.split) ? filePath.split('/').pop() : 'banner'
				const file = new File([blob], filename, { type: blob.type })
				const formData = new FormData()
				formData.append('file', file)
				const { data } = await axios.post(generateUrl('apps/social/api/v1/banner'), formData)
				this.bannerUrl = data.result.url
				await this.showSuccess(t('social', 'Banner uploaded successfully'))
				// Refresh account info so UI/store and potential ActivityPub broadcasts are in sync
				try {
					this.$store && this.$store.dispatch && await this.$store.dispatch('fetchAccountInfo', this.profileAccount)
				} catch (e) {
					// non-fatal
				}
			} catch (error) {
				await this.showError(t('social', 'Failed to upload banner'))
			} finally {
				this.loading = false
			}
		},
		async showSuccess(message) {
			const { showSuccess } = await import('@nextcloud/dialogs')
			showSuccess(message)
		},
		async showError(message) {
			const { showError } = await import('@nextcloud/dialogs')
			showError(message)
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

		&--editable {
			cursor: pointer;
		}
	}

	&__banner-upload {
		position: absolute;
		top: 12px;
		right: 12px;
		z-index: 10;
		opacity: 1;
		transition: opacity 0.2s;
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

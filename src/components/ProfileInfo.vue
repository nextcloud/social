<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		v-if="profileAccount && accountInfo"
		class="user-profile"
		:style="accent ? { '--profile-accent': accent, '--profile-accent-text': 'var(--color-primary-element-text)' } : {}">
		<!-- decorative: the banner is changed from the Edit profile dialog, and
		     a click here is a shortcut for people who have a pointer, not the
		     only way in -->
		<div
			ref="bannerEl"
			class="user-profile__banner"
			aria-hidden="true"
			:class="{
				'user-profile__banner--editable': isOwnProfile,
				'user-profile__banner--visible': bannerStyle !== '',
			}"
			@click="isOwnProfile ? openFilePicker() : undefined" />
		<!-- hidden fallback input for environments where the dialogs picker API is incompatible -->
		<input
			ref="bannerInput"
			type="file"
			accept="image/*"
			style="display:none"
			@change="uploadBanner">
		<div class="user-profile__content">
			<!-- `disableMenu`, like every other avatar in this app: the
			     account preview a reader gets is this app's own, and
			     Nextcloud's would open over the top of the profile they are
			     already reading -->
			<NcAvatar
				v-if="isLocal"
				:user="localUid"
				:disableMenu="true"
				:disableTooltip="true"
				:size="128" />
			<NcAvatar
				v-else
				:url="accountInfo.avatar"
				:disableMenu="true"
				:disableTooltip="true"
				:size="128" />
			<h2>{{ displayName }}</h2>
			<span v-if="relationship && relationship.blocking" class="user-profile__blocked-hint">
				{{ t('social', 'Blocked') }}
			</span>
			<!-- a timed mute says when it lifts; a permanent one has nothing
			     to add to the word in the menu -->
			<span v-else-if="relationship && relationship.muting" class="user-profile__blocked-hint">
				{{ muteExpiry
					? t('social', 'Muted until {date}', { date: muteExpiry })
					: t('social', 'Muted') }}
			</span>
			<!-- one string each, counted and formatted: "1 posts" was wrong in
			     English and the three labels were unpluralisable in every
			     language that does not build them the way English does -->
			<ul class="user-profile__info user-profile__sections">
				<li>
					<router-link :to="{ name: 'profile', params: { account: uid } }">
						{{ postsLabel }}
					</router-link>
				</li>
				<li>
					<router-link :to="{ name: 'profile.following', params: { account: uid } }">
						{{ followingLabel }}
					</router-link>
				</li>
				<li>
					<router-link :to="{ name: 'profile.followers', params: { account: uid } }">
						{{ followersLabel }}
					</router-link>
				</li>
			</ul>
			<div class="user-profile__actions">
				<FollowButton v-if="!relationship || !relationship.blocking" :uid="uid" />
				<NcButton
					v-if="serverData.public"
					variant="primary"
					@click="followRemote">
					{{ t('social', 'Follow') }}
				</NcButton>
				<NcButton
					v-if="isOwnProfile"
					variant="tertiary"
					:disabled="openingProfile"
					@click="openProfileModal">
					<template #icon>
						<TableEdit :size="20" />
					</template>
					{{ t('social', 'Edit profile') }}
				</NcButton>
				<NcActions v-if="canModerate" forceMenu>
					<NcActionButton
						v-if="!relationship.blocking"
						:disabled="relationshipLoading"
						closeAfterClick
						@click="toggleBlock">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('social', 'Block') }}
					</NcActionButton>
					<NcActionButton
						v-else
						:disabled="relationshipLoading"
						closeAfterClick
						@click="toggleBlock">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('social', 'Unblock') }}
					</NcActionButton>
					<NcActionButton
						v-if="!relationship.muting"
						:disabled="relationshipLoading"
						closeAfterClick
						@click="showMuteDialog = true">
						<template #icon>
							<VolumeOff :size="20" />
						</template>
						{{ t('social', 'Mute') }}
					</NcActionButton>
					<NcActionButton
						v-else
						:disabled="relationshipLoading"
						closeAfterClick
						@click="toggleMute">
						<template #icon>
							<VolumeHigh :size="20" />
						</template>
						{{ t('social', 'Unmute') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="showListDialog = true">
						<template #icon>
							<IconFormatListBulleted :size="20" />
						</template>
						{{ t('social', 'Add to list') }}
					</NcActionButton>
				</NcActions>
			</div>
			<!-- a private note, for the reader alone: it never leaves this
			     server and the account it is about is never told of it -->
			<form v-if="canModerate" class="user-profile__private-note" @submit.prevent="saveNote">
				<label class="user-profile__private-note-label" for="social-profile-private-note">
					{{ t('social', 'Your private note about this account') }}
				</label>
				<textarea
					id="social-profile-private-note"
					v-model="noteDraft"
					class="user-profile__private-note-input"
					rows="2"
					maxlength="2000"
					:placeholder="t('social', 'Only you ever see this.')" />
				<div class="user-profile__private-note-actions">
					<NcButton
						variant="secondary"
						type="submit"
						:disabled="!noteChanged || savingNote">
						{{ savingNote ? t('social', 'Saving…') : t('social', 'Save note') }}
					</NcButton>
				</div>
			</form>
			<!-- Sanitized: a bio is HTML, remote ones from anywhere, see sanitizeHtml.js -->
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div v-if="note" class="user-profile__note" v-html="note" />
			<!-- what this account is like, over and above how much of it there
			     is; renders nothing for a remote account, whose history this
			     instance only ever holds a part of -->
			<ProfileHighlights :accountId="highlightsAccountId" />
			<dl v-if="profileFields.length" class="user-profile__fields">
				<div
					v-for="(field, index) in profileFields"
					:key="index"
					class="user-profile__field"
					:class="{ 'user-profile__field--verified': field.verified }">
					<dt>{{ field.name }}</dt>
					<dd>
						<a
							v-if="field.href"
							:href="field.href"
							target="_blank"
							rel="nofollow noopener noreferrer">{{ field.text }}</a>
						<template v-else>
							{{ field.text }}
						</template>
						<VerifiedCheck v-if="field.verified" :verifiedAt="field.verifiedAt" />
					</dd>
				</div>
			</dl>
			<NcModal
				v-if="showProfileModal"
				:name="t('social', 'Edit profile')"
				@close="showProfileModal = false">
				<div class="user-profile__fields-modal">
					<h3>{{ t('social', 'Edit profile') }}</h3>
					<div class="user-profile__banner-edit">
						<span class="user-profile__banner-edit-label">{{ t('social', 'Banner') }}</span>
						<NcButton :disabled="loading" @click="openFilePicker">
							<template #icon>
								<ImagePlus :size="20" />
							</template>
							{{ loading ? t('social', 'Uploading…') : t('social', 'Upload an image') }}
						</NcButton>
						<label class="user-profile__banner-edit-or" for="social-profile-banner-url">
							{{ t('social', 'or give the address of one') }}
						</label>
						<div class="user-profile__banner-edit-url">
							<input
								id="social-profile-banner-url"
								v-model="bannerUrlInput"
								type="url"
								:placeholder="t('social', 'https://example.com/image.jpg')"
								@keyup.enter="uploadBannerByUrl">
							<NcButton :disabled="!bannerUrlInput || loadingUrl" @click="uploadBannerByUrl">
								{{ loadingUrl ? t('social', 'Downloading…') : t('social', 'Apply') }}
							</NcButton>
						</div>
					</div>
					<div class="user-profile__bio">
						<label class="user-profile__bio-label" for="social-profile-bio">
							{{ t('social', 'Bio') }}
						</label>
						<textarea
							id="social-profile-bio"
							v-model="bioDraft"
							class="user-profile__bio-input"
							rows="5"
							aria-describedby="social-profile-bio-count"
							:aria-invalid="bioTooLong ? 'true' : 'false'"
							:placeholder="t('social', 'A few words about you, shown on your profile and shared with other servers.')" />
						<!-- no maxlength: the server truncates an over-long bio instead of
						     refusing it, and silently swallowing the tail of a pasted bio
						     is worse than saying that it is too long -->
						<span
							id="social-profile-bio-count"
							class="user-profile__bio-count"
							:class="{ 'user-profile__bio-count--over': bioTooLong }"
							role="status">
							{{ bioCharactersLeftLabel }}
						</span>
					</div>
					<p>{{ t('social', 'Up to four name/value pairs, shown on your profile and shared with other servers.') }}</p>
					<div v-for="(row, index) in fieldRows" :key="index" class="user-profile__fields-row">
						<input
							v-model="row.name"
							type="text"
							maxlength="255"
							:aria-label="t('social', 'Label of field {number}', { number: index + 1 })"
							:placeholder="t('social', 'Label')">
						<input
							v-model="row.value"
							type="text"
							maxlength="500"
							:aria-label="t('social', 'Content of field {number}', { number: index + 1 })"
							:placeholder="t('social', 'Content')">
						<NcButton
							variant="tertiary"
							:aria-label="t('social', 'Remove field')"
							@click="fieldRows.splice(index, 1)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</div>
					<div class="user-profile__fields-modal-actions">
						<NcButton
							v-if="fieldRows.length < 4"
							variant="tertiary"
							@click="fieldRows.push({ name: '', value: '' })">
							{{ t('social', 'Add field') }}
						</NcButton>
						<NcButton variant="primary" :disabled="savingProfile || bioTooLong" @click="saveProfile">
							{{ savingProfile ? t('social', 'Saving…') : t('social', 'Save') }}
						</NcButton>
					</div>
				</div>
			</NcModal>
			<MuteDialog
				v-if="showMuteDialog"
				v-model:open="showMuteDialog"
				:account="accountInfo" />
			<ListMembershipDialog
				v-if="showListDialog"
				v-model:open="showListDialog"
				:account="accountInfo" />
		</div>
	</div>
</template>

<script>
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Close from 'vue-material-design-icons/Close.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import ImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import TableEdit from 'vue-material-design-icons/TableEdit.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import { generateUrl } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import FollowButton from './FollowButton.vue'
import ProfileHighlights from './ProfileHighlights.vue'
import VerifiedCheck from './VerifiedCheck.vue'
import { asAccent, dominantColour } from '../utils/dominantColour.js'
import { formatCount } from '../utils/number.js'
import { profileFields } from '../utils/profileFields.js'
import { sanitizeHtml } from '../utils/sanitizeHtml.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useAccount } from '../composables/useAccount.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'
import { fullDateTime } from '../utils/relativeTime.js'
import { defineAsyncComponent } from 'vue'

// Neither is any use until the reader asks for it, and both bring a dialog
// with them; they share one chunk, which is the same one the post overflow
// menu fetches its copy of the mute dialog from.
const ListMembershipDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./ListMembershipDialog.vue'))
const MuteDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./MuteDialog.vue'))

/** Mirrors `AccountService::SUMMARY_MAX_LENGTH`, which truncates beyond it. */
const BIO_MAX_LENGTH = 500

/**
 * A bio as `AccountService::plainSummary()` stores it, so that what is counted
 * and what is sent are what ends up on the profile.
 *
 * @param {string} bio - the text in the edit box
 * @return {string}
 */
function normalizeBio(bio) {
	return (bio ?? '').replace(/\r\n|\r/g, '\n').trim()
}

export default {
	name: 'ProfileInfo',
	components: {
		Cancel,
		Close,
		FollowButton,
		IconFormatListBulleted,
		ListMembershipDialog,
		MuteDialog,
		NcActionButton,
		NcActions,
		NcAvatar,
		NcButton,
		NcModal,
		ProfileHighlights,
		ImagePlus,
		TableEdit,
		VerifiedCheck,
		VolumeHigh,
		VolumeOff,
	},

	props: {
		uid: {
			type: String,
			default: '',
		},
	},

	setup(props) {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()
		const { profileAccount, accountInfo, isLocal, relationship } = useAccount(() => props.uid)

		return { serverData, currentUser, profileAccount, accountInfo, isLocal, relationship }
	},

	data() {
		return {
			followingText: t('social', 'Following'),
			bannerUrl: null,
			loading: false,
			bannerUrlInput: '',
			loadingUrl: false,
			/** the banner's own colour, tinting this profile only */
			accent: '',
			relationshipLoading: false,
			showProfileModal: false,
			/** the editor reads the profile before it opens; this is that read */
			openingProfile: false,
			fieldRows: [],
			savingProfile: false,
			bioDraft: '',
			/** the bio as it was when the editor opened, to tell a change from a no-op */
			bioStored: '',
			showMuteDialog: false,
			showListDialog: false,
			/** the private note as it is being edited */
			noteDraft: '',
			/** the note as the relationship last reported it */
			noteStored: '',
			savingNote: false,
		}
	},

	computed: {
		...mapStores(useAccountStore),
		localUid() {
			return (this.uid.indexOf('@') === -1) ? this.uid : this.uid.slice(0, this.uid.indexOf('@'))
		},

		displayName() {
			return this.accountInfo.display_name ?? this.accountInfo.username ?? this.profileAccount
		},

		/**
		 * Which account the highlights are asked for.
		 *
		 * The numeric id when the entity carries one, and the handle
		 * otherwise: the route takes either, and the id is the cheaper lookup
		 * of the two. Empty until the account has loaded, which is what keeps
		 * the component from asking for a profile nobody is looking at yet.
		 *
		 * @return {string}
		 */
		highlightsAccountId() {
			return String(this.accountInfo?.id ?? this.profileAccount ?? '')
		},

		/**
		 * The three counters under the name. The number is a placeholder
		 * rather than `%n` so that it can be written in the reader's own
		 * digits and grouping; the count still decides which form is used.
		 *
		 * @return {string}
		 */
		postsLabel() {
			const count = Number(this.accountInfo.statuses_count) || 0

			return translatePlural('social', '{count} post', '{count} posts', count, { count: formatCount(count) })
		},

		/** @return {string} */
		followingLabel() {
			const count = Number(this.accountInfo.following_count) || 0

			return translatePlural('social', '{count} following', '{count} following', count, { count: formatCount(count) })
		},

		/** @return {string} */
		followersLabel() {
			const count = Number(this.accountInfo.followers_count) || 0

			return translatePlural('social', '{count} follower', '{count} followers', count, { count: formatCount(count) })
		},

		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		},

		/**
		 * Remote field values arrive as HTML; render only their text, as a
		 * link when the field is one.
		 *
		 * @return {Array} [{name, text, href}]
		 */
		profileFields() {
			return profileFields(this.accountInfo.fields)
		},

		/** @return {string} the bio to show, reduced to markup that is safe to inject */
		note() {
			return sanitizeHtml(this.accountInfo.note ?? '')
		},

		/** @return {string} the bio as it would be stored */
		bioValue() {
			return normalizeBio(this.bioDraft)
		},

		/** @return {number} how many characters the bio has left */
		bioCharsLeft() {
			// `mb_strlen()` on the server counts code points, and `.length`
			// counts UTF-16 units: an emoji is one character, not two
			return BIO_MAX_LENGTH - [...this.bioValue].length
		},

		/** @return {boolean} */
		bioTooLong() {
			return this.bioCharsLeft < 0
		},

		/** @return {string} */
		bioCharactersLeftLabel() {
			return this.bioTooLong
				? this.n('social', '%n character too many', '%n characters too many', -this.bioCharsLeft)
				: this.n('social', '%n character left', '%n characters left', this.bioCharsLeft)
		},

		/** @return {boolean} whether the bio is worth mentioning in the request */
		bioChanged() {
			return this.bioValue !== this.bioStored
		},

		isOwnProfile() {
			return this.currentUser?.uid && this.localUid === this.currentUser.uid
		},

		/** @return {string} when a timed mute lifts, written out, or '' */
		muteExpiry() {
			return this.relationship?.mute_expires_at
				? fullDateTime(this.relationship.mute_expires_at)
				: ''
		},

		/** @return {boolean} whether the note is worth saving */
		noteChanged() {
			return this.noteDraft.trim() !== this.noteStored
		},

		/** @return {boolean} whether the block/mute menu applies to this profile */
		canModerate() {
			return !this.serverData.public && !this.isOwnProfile && this.relationship !== undefined
		},

		bannerStyle() {
			const info = this.accountInfo || {}
			return this.bannerUrl || info.header || ''
		},
	},

	watch: {
		// the note is part of the relationship, which arrives after the page
		// does and again after every block, mute or follow
		'relationship.note': {
			immediate: true,
			handler(note) {
				const stored = note ?? ''
				// not over something half-written: an answer to a mute taken
				// meanwhile would otherwise take back what was typed since
				if (this.noteDraft === this.noteStored) {
					this.noteDraft = stored
				}
				this.noteStored = stored
			},
		},

		bannerStyle: {
			handler(url) {
				this.applyBanner(url)
				this.readAccent(url)
			},

			immediate: true,
		},
	},

	// The immediate watcher above runs before the banner element exists and bails,
	// so paint any existing header once the ref is available on first render.
	mounted() {
		this.applyBanner(this.bannerStyle)
	},

	methods: {
		/**
		 * Tints this profile with its own banner. A banner that cannot be read
		 * — cross-origin, missing, transparent — leaves the theme's colour in
		 * place, which is what every profile looked like before.
		 *
		 * @param {string} url the banner currently shown
		 */
		async readAccent(url) {
			if (!url) {
				this.accent = ''
				return
			}

			this.accent = asAccent(await dominantColour(url))
		},

		async toggleBlock() {
			this.relationshipLoading = true
			try {
				const action = this.relationship.blocking ? 'unblockAccount' : 'blockAccount'
				await this.accountStore[action]({ id: this.relationship.id })
			} finally {
				this.relationshipLoading = false
			}
		},

		/** Only ever unmutes: muting has questions, and the dialog asks them. */
		async toggleMute() {
			this.relationshipLoading = true
			try {
				await this.accountStore.unmuteAccount({ id: this.relationship.id })
			} finally {
				this.relationshipLoading = false
			}
		},

		/**
		 * Keeps the private note. An empty box clears it, which is what the
		 * route reads an empty `comment` as.
		 */
		async saveNote() {
			if (this.savingNote || !this.noteChanged) {
				return
			}
			this.savingNote = true
			const comment = this.noteDraft.trim()
			try {
				const { data } = await axios.post(
					generateUrl(`apps/social/api/v1/accounts/${this.relationship.id}/note`),
					{ comment },
				)
				this.noteStored = data?.note ?? comment
				this.noteDraft = this.noteStored
				await this.showSuccess(comment === ''
					? t('social', 'Your note has been cleared')
					: t('social', 'Your note has been saved'))
			} catch (error) {
				logger.error('Failed to save the note about this account', { error })
				await this.showError(t('social', 'Could not save your note'))
			} finally {
				this.savingNote = false
			}
		},

		/**
		 * Opens the editor on what is actually stored.
		 *
		 * The bio has to be asked for, and this is why: `source` is an
		 * account's own editable copy of its profile, and it lives on exactly
		 * two routes -- `verify_credentials` and `update_credentials` -- because
		 * it carries `follow_requests_count`, which is nobody's business but the
		 * account's own. This page is drawn from `/global/account/info`, which
		 * is answered to anybody and therefore has no `source` at all, so
		 * `source.note` was `undefined` here and the editor opened with an empty
		 * box over a bio that was still there. `note` is not a substitute: it is
		 * the rendered HTML and would put markup in a plain-text field.
		 *
		 * Read before the dialog opens rather than after, so there is never a
		 * moment where an empty box is on screen and typing into it would be
		 * typing over something.
		 */
		async openProfileModal() {
			if (this.openingProfile) {
				return
			}

			this.openingProfile = true
			let source = null
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/accounts/verify_credentials'))
				source = data?.source ?? null
			} catch (error) {
				logger.error('Could not read the profile to edit', { error })
				await this.showError(t('social', 'Could not load your profile for editing'))
			} finally {
				this.openingProfile = false
			}

			// `??`, not `||`: an account with no fields answers `[]`, which is
			// truthy, and `||` would quietly fall back to the rendered copy on
			// the page. The fallback is for a `source` that could not be read
			// at all, and for nothing else.
			const fields = source?.fields ?? this.accountInfo.fields ?? []
			this.fieldRows = fields.map((field) => ({ name: field.name, value: field.value }))
			if (this.fieldRows.length === 0) {
				this.fieldRows.push({ name: '', value: '' })
			}

			// An unreadable profile leaves both of these empty and equal, which
			// is what `bioChanged` reads: a bio nobody could load is never one
			// this editor sends back, so a failure here cannot erase it.
			this.bioStored = normalizeBio(source?.note)
			this.bioDraft = this.bioStored
			this.showProfileModal = true
		},

		async saveProfile() {
			if (this.savingProfile || this.bioTooLong) {
				return
			}

			this.savingProfile = true
			try {
				const fields = this.fieldRows
					.map((row) => ({ name: row.name.trim(), value: row.value.trim() }))
					.filter((row) => row.name !== '' && row.value !== '')
				await axios.put(generateUrl('apps/social/api/v1/account/fields'), { fields })
				// an absent `note` leaves the stored bio alone, so it is sent
				// only when this editor actually changed it
				if (this.bioChanged) {
					await axios.patch(
						generateUrl('apps/social/api/v1/accounts/update_credentials'),
						{ note: this.bioValue },
					)
				}
				this.showProfileModal = false
				await this.showSuccess(t('social', 'Profile saved'))
				try {
					await this.accountStore.fetchAccountInfo(this.profileAccount)
				} catch (e) {
					logger.warn('Could not refresh the account after saving the profile', { error: e })
				}
			} catch (error) {
				logger.error('Failed to save the profile', { error })
				await this.showError(t('social', 'Failed to save profile'))
			} finally {
				this.savingProfile = false
			}
		},

		followRemote() {
			window.open(generateUrl('/apps/social/api/v1/ostatus/followRemote/' + encodeURI(this.localUid)), 'followRemote', 'width=433,height=600,toolbar=no,menubar=no,scrollbars=yes,resizable=yes')
		},

		async openFilePicker() {
			if (this.$refs.bannerInput) {
				this.$refs.bannerInput.click()
			}
		},

		async uploadBanner(event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}
			// the file name is the reader's own document title; it does not
			// belong in a console every extension can read
			logger.debug('Uploading a banner', { size: file.size, type: file.type })
			this.loading = true
			try {
				const formData = new FormData()
				formData.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/banner'),
					formData,
				)
				this.bannerUrl = data.result.url
				await this.showSuccess(t('social', 'Banner uploaded successfully'))
				try {
					await this.accountStore.fetchAccountInfo(this.profileAccount)
				} catch (e) {
					logger.warn('Could not refresh the account after the banner upload', { error: e })
				}
			} catch (error) {
				logger.error('Failed to upload the banner', { error })
				await this.showError(this.uploadFailure(
					error,
					t('social', 'Failed to upload banner'),
				))
			} finally {
				this.loading = false
				if (event && event.target) {
					event.target.value = ''
				}
			}
		},

		async uploadBannerByUrl() {
			const url = this.bannerUrlInput.trim()
			if (!url) {
				return
			}
			this.loadingUrl = true
			try {
				const formData = new URLSearchParams()
				formData.append('url', url)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/banner/url'),
					formData,
					{ headers: { 'Content-Type': 'application/x-www-form-urlencoded' } },
				)
				this.bannerUrl = data.result.url
				this.bannerUrlInput = ''
				await this.showSuccess(t('social', 'Banner set successfully'))
				try {
					await this.accountStore.fetchAccountInfo(this.profileAccount)
				} catch (e) {
					logger.warn('Could not refresh the account after setting the banner', { error: e })
				}
			} catch (error) {
				logger.error('Failed to set the banner from a URL', { error })
				await this.showError(this.uploadFailure(
					error,
					t('social', 'Failed to set banner from URL'),
				))
			} finally {
				this.loadingUrl = false
			}
		},

		/**
		 * What to put on screen when a banner is refused.
		 *
		 * The server says why whenever the reason is the reader's to act on --
		 * the picture is too big for this server, or is not one it can read --
		 * and that is the whole of what they need. "Failed to upload banner"
		 * is what is left when the failure was this side's, and says as much
		 * as it honestly can: it is the fallback, not the answer.
		 *
		 * @param {object} error the axios failure
		 * @param {string} fallback what to say when the server offered nothing
		 * @return {string} the message to show
		 */
		uploadFailure(error, fallback) {
			const message = error?.response?.data?.message

			return (typeof message === 'string' && message !== '') ? message : fallback
		},

		// the two wrappers below are what the rest of this component calls; the
		// service they forward to fetches the toast library on first use
		async showSuccess(message) {
			await showSuccess(message)
		},

		async showError(message) {
			await showError(message)
		},

		applyBanner(url) {
			const el = this.$refs.bannerEl
			if (!el) {
				return
			}
			if (url) {
				el.style.backgroundImage = `url(${url})`
				el.style.backgroundSize = 'cover'
				el.style.backgroundPosition = 'center 0%'
				el.style.backgroundRepeat = 'no-repeat'
				el.style.backgroundColor = ''
			} else {
				el.style.backgroundImage = ''
				el.style.backgroundColor = 'var(--color-background-dark)'
			}
		},

		t: translate,
		n: translatePlural,
	},
}
</script>

<style scoped lang="scss">
.user-profile {
	display: flex;
	flex-direction: column;
	/* a profile arrives as a card rather than appearing */
	animation: profile-settle .4s cubic-bezier(.22, 1, .36, 1) both;
	align-items: center;
	width: 100%;
	max-width: var(--social-column);
	margin: 0 auto calc(var(--default-grid-baseline) * 6);
	text-align: center;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
	position: relative;

	&__banner {
		/* the card centres its children, and this one has no content of its
		   own — only a background — so without a width of its own it collapses
		   to nothing and the banner is loaded, applied and never seen */
		width: 100%;
		min-height: 120px;
		max-height: 200px;
		/* the banner drifts a little slower than the page it is on */
		will-change: transform;
		background-size: cover;
		background-position: center 0%;
		background-repeat: no-repeat;
		background-color: var(--color-background-dark);

		&--editable {
			cursor: pointer;
		}
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

	&__blocked-hint {
		margin-top: 4px;
		padding: 2px 10px;
		border-radius: var(--border-radius-pill, 12px);
		background: var(--color-background-dark);
		color: var(--color-text-lighter);
		font-size: 13px;
		font-weight: 600;
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
		margin: 18px 0 0;
		padding: 18px 0 0;
		border-top: 1px solid var(--color-border);
		font-size: 14px;
		line-height: 1.7;
		overflow-wrap: break-word;
		white-space: pre-wrap;
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

				/* the profile's own colour, where the banner yielded one */
				&.router-link-exact-active {
					background: var(--profile-accent, var(--color-background-hover));
					color: var(--profile-accent-text, inherit);
				}

				/* focus does not borrow the banner's colour: an indicator whose
				   contrast depends on somebody's uploaded picture is not one */
				&:focus-visible {
					outline: 2px solid var(--color-primary-element);
					outline-offset: 1px;
					background: var(--color-background-hover);
					color: inherit;
				}

				&.disabled {
					text-decoration: none;
					cursor: auto;
					pointer-events: none;
				}
			}
		}
	}

	&__fields {
		width: 100%;
		margin: 12px 0 0;
		padding: 12px calc(var(--default-grid-baseline) * 4) 0;
		border-top: 1px solid var(--color-border);
		background: var(--color-main-background);
		text-align: start;
	}

	&__field {
		display: flex;
		gap: 12px;
		padding: 6px 0;
		font-size: 14px;

		dt {
			flex: 0 0 30%;
			font-weight: 600;
			color: var(--color-text-lighter);
			overflow-wrap: break-word;
		}

		dd {
			flex: 1;
			overflow-wrap: anywhere;

			a {
				color: var(--color-primary-element);

				&:hover {
					text-decoration: underline;
				}
			}
		}
	}

	&__fields-modal {
		padding: 32px;
		display: flex;
		flex-direction: column;
		gap: 12px;

		h3 {
			margin: 0;
			font-size: 18px;
			font-weight: 700;
		}

		p {
			color: var(--color-text-lighter);
		}
	}

	&__fields-row {
		display: flex;
		gap: 8px;
		align-items: center;

		input {
			flex: 1;
			padding: 8px 10px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			font-size: 14px;
			background: var(--color-main-background);
			color: var(--color-main-text);

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}
	}

	&__private-note {
		display: flex;
		flex-direction: column;
		gap: 4px;
		width: 100%;
		max-width: 420px;
		margin-block: 8px 12px;
	}

	&__private-note-label {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__private-note-input {
		width: 100%;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		font-size: 14px;
		line-height: 1.5;
		resize: vertical;
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__private-note-actions {
		display: flex;
		justify-content: flex-end;
	}

	&__bio {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__bio-label {
		font-weight: 600;
	}

	&__bio-input {
		width: 100%;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		font-size: 14px;
		line-height: 1.5;
		resize: vertical;
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	&__bio-count {
		align-self: flex-end;
		font-size: 13px;
		color: var(--color-text-lighter);

		&--over {
			color: var(--color-error-text, var(--color-error));
			font-weight: 600;
		}
	}

	&__fields-modal-actions {
		display: flex;
		justify-content: space-between;
		gap: 8px;
	}

	&__banner-edit {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding-bottom: 16px;
		border-bottom: 1px solid var(--color-border);
	}

	&__banner-edit-label {
		font-weight: 600;
	}

	&__banner-edit-or {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__banner-edit-url {
		display: flex;
		gap: 8px;
		align-items: center;

		input {
			// the Apply button takes what it needs; the address takes the rest
			flex: 1 1 auto;
			min-width: 0;
			padding: 10px 12px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			font-size: 14px;
			background: var(--color-main-background);
			color: var(--color-main-text);

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}
	}

}

/**
 * A profile arrives as a card rather than appearing: the banner settles, and
 * the avatar and name follow it a beat later.
 */
@keyframes profile-settle {
	from {
		opacity: 0;
		transform: translateY(10px);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

@supports (animation-timeline: view()) {
	@media (prefers-reduced-motion: no-preference) {
		@keyframes banner-drift {
			from { transform: translateY(-6%) scale(1.06); }
			to { transform: translateY(2%) scale(1.06); }
		}

		.user-profile__banner--visible {
			animation: banner-drift linear both;
			animation-timeline: view();
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.user-profile {
		animation: none;
	}
}
</style>

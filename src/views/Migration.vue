<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="migration">
		<h2>{{ t('social', 'Migration') }}</h2>
		<p class="migration__hint">
			{{ t('social', 'Your account is yours. Take a copy of it whenever you like, move it to another server, or bring an account here from somewhere else.') }}
		</p>

		<!-- out -->
		<section class="migration__card">
			<h3>
				<IconDownload :size="20" />
				{{ t('social', 'Export your data') }}
			</h3>
			<p>
				{{ t('social', 'A zip file holding your profile, the people you follow, your followers, the accounts you block and mute, your bookmarks and likes, and every post you have written.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Your private key is deliberately not in it. An archive is an ordinary file that can be copied anywhere, and a key that could sign as you cannot be taken back once it has been seen.') }}
			</p>
			<NcButton
				variant="primary"
				:disabled="exporting"
				@click="exportArchive">
				<template #icon>
					<NcLoadingIcon v-if="exporting" :size="20" />
					<IconDownload v-else :size="20" />
				</template>
				{{ exporting ? t('social', 'Preparing the archive …') : t('social', 'Export') }}
			</NcButton>
		</section>

		<!-- in -->
		<section class="migration__card">
			<h3>
				<IconUpload :size="20" />
				{{ t('social', 'Import an archive') }}
			</h3>
			<p>
				{{ t('social', 'Reads an archive from the Export button — or from a Nextcloud account export — back into this account. Nothing is deleted: your profile, follows, blocks, mutes, bookmarks and likes are restored alongside what is already here.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Posts in the archive are listed rather than published again, so importing cannot flood the timelines of people who follow you.') }}
			</p>
			<input
				ref="archive"
				type="file"
				accept=".zip,application/zip"
				class="hidden-visually"
				@change="importArchive">
			<NcButton :disabled="importing" @click="$refs.archive.click()">
				<template #icon>
					<NcLoadingIcon v-if="importing" :size="20" />
					<IconUpload v-else :size="20" />
				</template>
				{{ importing ? t('social', 'Importing …') : t('social', 'Import') }}
			</NcButton>

			<ul v-if="importLog.length" class="migration__log">
				<li v-for="(line, index) in importLog" :key="index">
					{{ line }}
				</li>
			</ul>
		</section>

		<!-- from elsewhere -->
		<section class="migration__card">
			<h3>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Coming from another network') }}
			</h3>
			<p>
				{{ t('social', 'The fediverse is one network with many doors. Mastodon, Pixelfed, GoToSocial, Akkoma, Misskey and this app all speak ActivityPub, so an account here can follow and be followed by any of them — and what you bring with you is mostly the list of people you had found.') }}
			</p>

			<h4>{{ t('social', 'Bring your follows with you') }}</h4>
			<p>
				{{ t('social', 'Every one of those servers exports the people you follow as a following_accounts.csv. Upload that file and each account is followed again from here. A follow is an agreement between two servers, so it has to be asked for again — it cannot be copied out of a file.') }}
			</p>
			<input
				ref="follows"
				type="file"
				accept=".csv,text/csv"
				class="hidden-visually"
				@change="importFollows">
			<NcButton :disabled="followsBusy" @click="$refs.follows.click()">
				<template #icon>
					<NcLoadingIcon v-if="followsBusy" :size="20" />
					<IconAccountMultiplePlus v-else :size="20" />
				</template>
				{{ followsBusy ? t('social', 'Following …') : t('social', 'Import follows from a CSV') }}
			</NcButton>
			<p v-if="followsResult" class="migration__result">
				{{ followsResult }}
			</p>

			<h4>{{ t('social', 'Where to find that file') }}</h4>
			<ul class="migration__list">
				<li>
					<strong>{{ t('social', 'Mastodon') }}</strong>
					{{ t('social', '— Preferences → Import and export → Data export → Follows (CSV). The archive there also holds your posts and media.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Pixelfed') }}</strong>
					{{ t('social', '— Settings → Data export → Following. Photos come across as posts once you follow the accounts again.') }}
				</li>
				<li>
					<strong>{{ t('social', 'GoToSocial and Akkoma') }}</strong>
					{{ t('social', '— Settings → Export, which writes the same Mastodon-shaped CSV.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Bluesky, Threads and X') }}</strong>
					{{ t('social', '— these do not speak ActivityPub in a way that carries a follow list, so there is nothing here to import. Bluesky accounts can be followed through a bridge if the other side has opted in.') }}
				</li>
			</ul>

			<h4>{{ t('social', 'Moving your whole account') }}</h4>
			<p>
				{{ t('social', 'Telling your old server to redirect your followers here is a one-way move that federates to every server that knows you, so it is an administrator action rather than a button: ask for occ social:account:alias to name this account on the old one, then occ social:account:move to carry the followers over.') }}
			</p>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconDownload from 'vue-material-design-icons/Download.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/**
 * Taking the account out of the app, and bringing one in.
 *
 * The page exists because the alternative was `occ` and an app the admin has
 * to install: a person should be able to take a copy of what they wrote
 * without asking anybody. The export and the import drive the same
 * `SocialMigrator` Nextcloud's whole-account export uses, so an archive from
 * here and one from `occ user:export` are the same files under the same names,
 * and either can be read back.
 *
 * The third section is the honest part of moving between networks: what
 * actually travels is the list of people you follow, because a follow is a
 * relationship two servers agree on rather than a row in a file. Everything
 * else about "migrating from X" is either a CSV in the same shape or, for the
 * networks that do not federate, nothing at all — and saying so is more use
 * than pretending otherwise.
 */
export default {
	name: 'Migration',

	components: {
		IconAccountArrowRight,
		IconAccountMultiplePlus,
		IconDownload,
		IconUpload,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			exporting: false,
			importing: false,
			followsBusy: false,
			/** @type {string[]} what the last import reported */
			importLog: [],
			/** @type {string} what the last CSV import came to */
			followsResult: '',
		}
	},

	methods: {
		t,

		/**
		 * The archive is built on the server and handed over as a blob, then
		 * saved through a link this code makes and clicks: the route needs the
		 * session, so a plain `window.open` of it would work, but an error
		 * would then replace the page with a JSON body instead of being caught
		 * here and said out loud.
		 */
		async exportArchive() {
			this.exporting = true
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/migration/export'),
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const link = document.createElement('a')
				link.href = url
				link.download = this.filenameOf(response) || 'social-export.zip'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
				showSuccess(t('social', 'Your archive is downloading'))
			} catch (error) {
				logger.error('The export failed', { error })
				showError(t('social', 'Could not export your data'))
			} finally {
				this.exporting = false
			}
		},

		/**
		 * @param {object} response what the server answered
		 * @return {string} the name the server gave the file, or ''
		 */
		filenameOf(response) {
			const disposition = response?.headers?.['content-disposition'] ?? ''
			const match = /filename="?([^";]+)"?/.exec(disposition)

			return match ? match[1] : ''
		},

		/**
		 * @param {Event} event the file input's change
		 */
		async importArchive(event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}

			this.importing = true
			this.importLog = []
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/import'),
					form,
				)
				this.importLog = Array.isArray(data?.log) ? data.log : []
				showSuccess(t('social', 'Your archive has been imported'))
			} catch (error) {
				logger.error('The import failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import that archive'))
			} finally {
				this.importing = false
				// cleared, or choosing the same file twice fires no change
				if (event?.target) {
					event.target.value = ''
				}
			}
		},

		/**
		 * @param {Event} event the file input's change
		 */
		async importFollows(event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}

			this.followsBusy = true
			this.followsResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/follows'),
					form,
				)
				const failed = Object.keys(data?.failed ?? {}).length
				this.followsResult = t(
					'social',
					'{followed} followed, {skipped} skipped, {failed} could not be reached',
					{ followed: data?.followed ?? 0, skipped: data?.skipped ?? 0, failed },
				)
				showSuccess(t('social', 'Your follows have been imported'))
			} catch (error) {
				logger.error('Importing follows failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import those follows'))
			} finally {
				this.followsBusy = false
				if (event?.target) {
					event.target.value = ''
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}
}

.migration__hint {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.migration__card {
	margin-bottom: 16px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);

	h3 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	h4 {
		margin: 20px 0 6px;
		font-weight: bold;
	}

	p {
		margin-bottom: 12px;
	}
}

.migration__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.migration__list {
	margin: 0 0 12px;
	padding: 0;
	list-style: none;

	li {
		margin-bottom: 6px;
		padding-inline-start: 14px;
		position: relative;

		&::before {
			content: '·';
			position: absolute;
			inset-inline-start: 2px;
		}
	}
}

.migration__log {
	margin-top: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	font-size: 13px;
	list-style: none;
	max-height: 240px;
	overflow-y: auto;
}

.migration__result {
	margin-top: 10px;
	font-weight: 500;
}
</style>

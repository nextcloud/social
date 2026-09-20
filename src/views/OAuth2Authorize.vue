<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="oauth">
		<!--
			After the consent form is submitted, the out-of-band flow lands
			back here with a code. It used to be answered with a JSON body, so
			a browser drew `{"code":"..."}` on a blank document one click after
			being promised the code would be shown -- which reads as the button
			having done nothing at all.
		-->
		<div v-if="code" class="guest-box">
			<h1>{{ t('social', 'Authorized') }}</h1>
			<p>
				{{ t('social', 'Paste this code into {appDisplayName} to finish signing in. It can be used once, and only by the application that asked for it.', { appDisplayName: appName }) }}
			</p>
			<!--
				An input rather than a <code> block: it is selectable, it
				survives a long code without wrapping it into something
				ambiguous, and a phone can pick it up with a tap.
			-->
			<input
				ref="code"
				class="code"
				type="text"
				readonly
				:value="code"
				:aria-label="t('social', 'Authorization code')"
				@focus="selectCode">
			<div class="button-row">
				<NcButton variant="primary" @click="copyCode">
					{{ copied ? t('social', 'Copied') : t('social', 'Copy code') }}
				</NcButton>
			</div>
		</div>

		<form v-else class="guest-box" method="post">
			<h1>{{ t('social', 'Authorization required') }}</h1>
			<p>
				{{ t('social', '{appDisplayName} would like permission to access your account. It is a third party application.', {appDisplayName: appName}) }}
				<b>{{ t('social', 'If you do not trust it, then you should not authorize it.') }}</b>
			</p>

			<h2>{{ t('social', 'This application will be able to:') }}</h2>
			<ul class="scopes">
				<li v-for="scope in scopes" :key="scope">
					<span class="scopes__label">{{ scopeLabel(scope) }}</span>
					<span class="scopes__name">{{ scope }}</span>
				</li>
			</ul>

			<p class="target">
				{{ targetDescription }}
			</p>

			<input
				type="hidden"
				name="requesttoken"
				:value="OC.requestToken">
			<div class="button-row">
				<NcButton variant="primary" type="submit">
					{{ t('social', 'Authorize') }}
				</NcButton>
				<NcButton variant="error" :href="denyUrl">
					{{ t('social', 'Deny') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const OUT_OF_BAND = 'urn:ietf:wg:oauth:2.0:oob'

export default {
	name: 'OAuth2Authorize',
	components: {
		NcButton,
	},

	data() {
		return {
			// present only on the page the consent form submits to, in the
			// out-of-band flow
			code: loadState('social', 'code', ''),
			copied: false,
			appName: loadState('social', 'appName'),
			scopes: loadState('social', 'scopes', []),
			redirectUri: loadState('social', 'redirectUri', ''),
			// where refusing sends the browser: back to the client with
			// error=access_denied, which is the answer it is owed
			denyUrl: loadState('social', 'denyUrl', generateUrl('/apps/social/')),
		}
	},

	computed: {
		/**
		 * What the scopes mean, one line each. An unknown scope is shown as it
		 * was asked for rather than hidden — consent to something unnamed is
		 * not consent.
		 *
		 * @return {object} the label of every scope this server issues
		 */
		scopeLabels() {
			return {
				read: t('social', 'Read all of your data'),
				write: t('social', 'Act as you: post, upload and change anything of yours'),
				follow: t('social', 'Follow, block and mute accounts for you'),
				'read:accounts': t('social', 'Read your profile'),
				'read:blocks': t('social', 'See the accounts you block'),
				'read:bookmarks': t('social', 'See your bookmarks'),
				'read:collections': t('social', 'See your collections'),
				'read:favourites': t('social', 'See your favourites'),
				'read:filters': t('social', 'See your filters'),
				'read:follows': t('social', 'See who you follow and who follows you'),
				'read:lists': t('social', 'See your lists'),
				'read:mutes': t('social', 'See the accounts you mute'),
				'read:notifications': t('social', 'Read your notifications'),
				'read:search': t('social', 'Search as you'),
				'read:statuses': t('social', 'Read your posts and your timelines'),
				'read:stories': t('social', 'See your stories'),
				'write:accounts': t('social', 'Change your profile'),
				'write:blocks': t('social', 'Block and unblock accounts for you'),
				'write:bookmarks': t('social', 'Add and remove your bookmarks'),
				'write:collections': t('social', 'Create and change your collections'),
				'write:conversations': t('social', 'Manage your conversations'),
				'write:favourites': t('social', 'Favourite and unfavourite posts as you'),
				'write:filters': t('social', 'Create and change your filters'),
				'write:follows': t('social', 'Follow and unfollow accounts for you'),
				'write:lists': t('social', 'Create and change your lists'),
				'write:media': t('social', 'Upload files as you'),
				'write:mutes': t('social', 'Mute and unmute accounts and conversations for you'),
				'write:notifications': t('social', 'Dismiss your notifications'),
				'write:reports': t('social', 'Send reports as you'),
				'write:statuses': t('social', 'Publish, edit and delete posts as you'),
				'write:stories': t('social', 'Publish and delete stories as you'),
			}
		},

		/**
		 * Where the authorization code is about to be sent — the one part of
		 * the request that decides who ends up holding it.
		 *
		 * @return {string} a sentence naming the destination
		 */
		targetDescription() {
			if (this.redirectUri === OUT_OF_BAND || this.redirectUri === '') {
				return t('social', 'The authorization code will be shown to you, to copy into the application yourself.')
			}

			return t('social', 'The authorization code will be sent to {target}.', { target: this.redirectTarget })
		},

		/**
		 * @return {string} the host the code travels to, or the whole URI when
		 *                  it has no host to name (a custom application scheme)
		 */
		redirectTarget() {
			try {
				const host = new URL(this.redirectUri).host
				if (host !== '') {
					return host
				}
			} catch {
				// not a URL this browser parses; show it whole
			}

			return this.redirectUri
		},
	},

	methods: {
		scopeLabel(scope) {
			return this.scopeLabels[scope] ?? scope
		},

		/** Focusing the field selects the whole code, so one tap picks it up. */
		selectCode() {
			this.$refs.code?.select()
		},

		async copyCode() {
			try {
				await navigator.clipboard.writeText(this.code)
				this.copied = true
			} catch {
				// no clipboard permission, or an insecure context: the field is
				// selectable and the code is on screen either way
				this.selectCode()
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* `scopped` for a long time, which quietly published all of this to every
   page that ever loaded this bundle. */
// `.wrapper` before, which is also what Nextcloud's guest layout calls its
// own container -- two elements one selector apart, which made this hard to
// look at in a browser.
.oauth {
	display: flex;
	flex-direction: column;
	align-items: center;
	// no viewport height here: the guest layout already places and centres
	// what it is given, and a second full-screen box inside it pushed the
	// form below the fold with its buttons off the bottom of the window
	inline-size: 100%;
}

.guest-box {
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	padding: 1rem;
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
	display: inline-block;
	max-width: 600px;

	h1 {
		font-weight: bold;
		text-align: center;
		font-size: 20px;
		margin-bottom: 12px;
		line-height: 140%;
	}

	h2 {
		font-weight: bold;
		font-size: 16px;
		margin-top: 1rem;
	}

	.scopes {
		margin: 0.5rem 0 0 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			flex-direction: column;
			padding: 4px 0;
			border-bottom: 1px solid var(--color-border);
		}

		&__name {
			color: var(--color-text-maxcontrast);
			font-size: 90%;
		}
	}

	.target {
		margin-top: 1rem;
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
	}

	.button-row {
		display: flex;
		gap: 1rem;
		flex-direction: row;
		margin-top: 1rem;
		justify-content: end;
	}

	.code {
		inline-size: 100%;
		margin-top: 1rem;
		font-family: monospace;
		text-align: center;
	}
}
</style>

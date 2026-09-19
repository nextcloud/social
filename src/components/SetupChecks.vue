<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="setup-checks">
		<template v-if="checks.wellknown === false">
			<h3>{{ t('social', '.well-known/webfinger isn\'t properly set up!') }}</h3>
			<p>
				{{ t('social', 'Social needs the .well-known automatic discovery to be properly set up. If Nextcloud is not installed in the root of the domain, it is often the case that Nextcloud cannot configure this automatically. To use Social, the administrator of this Nextcloud instance needs to manually configure the .well-known redirects:') }}
				<a
					class="external_link"
					href="https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL"
					target="_blank"
					rel="noreferrer noopener">
					{{ t('social', 'Open documentation') }} ↗
				</a>
			</p>
		</template>

		<template v-if="checks.clientApi === false">
			<h3>{{ t('social', 'Mastodon apps cannot connect to this server') }}</h3>
			<p>
				{{ clientApiExplanation }}
			</p>
			<p>
				{{ t('social', 'The web server has to map /api, /oauth and /.well-known/host-meta onto Social. It has to do that as an internal proxy and not as a redirect: Nextcloud routes on the address a request arrived at, so a plain rewrite answers 404, and many clients drop their authorization when they follow a redirect. Inside the Apache virtual host that serves Nextcloud:') }}
			</p>
			<pre class="setup-checks__config"><code>{{ apacheRules }}</code></pre>
			<p>
				{{ t('social', 'The rules for nginx, what each one is for, and what else changes once requests arrive through a proxy are in the documentation. Nothing else is affected: the web interface and federation with other servers work without any of this.') }}
				<a
					class="external_link"
					:href="clientApiDocs"
					target="_blank"
					rel="noreferrer noopener">
					{{ t('social', 'Open documentation') }} ↗
				</a>
			</p>
		</template>

		<template v-if="checks.cloudAddress === false">
			<h3>{{ t('social', 'Social is set up for a different address than this server') }}</h3>
			<p>
				{{ addressExplanation }}
			</p>
			<p>
				{{ t('social', 'Changing it renames every account and post that already exists here, so Social will not do it on its own. Either point the server back at the address Social knows, or reset Social with "occ social:reset" — which deletes everything it holds.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'

/** Where the whole story lives, nginx included. */
const CLIENT_API_DOCS = 'https://github.com/nextcloud/social/blob/master/docs/Admin.md#mastodon-apps-cannot-connect'

export default {
	name: 'SetupChecks',
	props: {
		checks: {
			type: Object,
			required: true,
		},

		/** The address Social builds ids from, and the one the server reports. */
		addresses: {
			type: Object,
			default: () => ({ configured: '', expected: '' }),
		},
	},

	data() {
		return {
			clientApiDocs: CLIENT_API_DOCS,
		}
	},

	computed: {
		/**
		 * The Apache rules, verbatim from contrib/webserver/apache-social-root.conf.
		 * Kept short on purpose — an administrator who needs the whole file, or
		 * nginx, follows the link underneath.
		 */
		apacheRules() {
			return [
				'ProxyPreserveHost On',
				'RewriteEngine On',
				'RewriteRule ^/?api/(.*)$   http://127.0.0.1/index.php/apps/social/api/$1   [P,QSA,L]',
				'RewriteRule ^/?oauth/(.*)$ http://127.0.0.1/index.php/apps/social/oauth/$1 [P,QSA,L]',
				'RewriteRule ^/?\\.well-known/host-meta$ http://127.0.0.1/index.php/.well-known/host-meta [P,QSA,L]',
			].join('\n')
		},

		clientApiExplanation() {
			return translate(
				'social',
				'A Mastodon app is given a domain and builds {example} from it. None of them can be told the {prefix} prefix these routes live under, so adding this server to an app fails at its first request, with nothing in the log to show for it.',
				{ example: 'https://' + window.location.host + '/api/v1/instance', prefix: '/index.php/apps/social' },
			)
		},

		addressExplanation() {
			return translate(
				'social',
				'Social builds every account and post address from {configured}, but this server now reports that it lives at {expected}. Nobody looking for an account here under the address the server advertises will find it.',
				{ configured: this.addresses.configured, expected: this.addresses.expected },
			)
		},
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.setup-checks {
	h3 {
		margin-top: 12px;
		font-weight: bold;
	}

	p {
		margin-bottom: 8px;
	}

	// the rules are one long line each and wider than the column. They wrap
	// rather than scroll out of sight, because a rule an administrator cannot
	// see is a rule they will paste half of -- a soft wrap is not part of what
	// gets copied, so the paste is still one line.
	&__config {
		margin-bottom: 8px;
		padding: 8px 12px;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
		border-radius: var(--border-radius);
		background-color: var(--color-background-dark);
		font-family: var(--font-face-monospace, monospace);
		font-size: 90%;
		user-select: text;
	}
}
</style>

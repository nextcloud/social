<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="setup-checks">
		<template v-if="!checks.wellknown">
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

	computed: {
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
}
</style>

<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="bluesky-move-error">
		<p :role="alert ? 'alert' : undefined" :class="{ 'bluesky-move-error__text': alert }">
			{{ error.text }}
		</p>
		<!-- the server's own words, in English, for whoever helps the person -->
		<details v-if="error.details && error.details !== error.text" class="bluesky-move-error__details">
			<summary>{{ t('social', 'Details') }}</summary>
			<code>{{ error.details }}</code>
		</details>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'

/**
 * Why a Bluesky move was refused or stopped, in the person's words
 * (utils/moveError.js), with what the server said behind "Details".
 */
export default {
	name: 'BlueskyMoveError',

	props: {
		/** `{text, details}`, as utils/moveError.js makes it */
		error: {
			type: Object,
			required: true,
		},

		/** whether it is the answer to what the person just did, read out at once */
		alert: {
			type: Boolean,
			default: true,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.bluesky-move-error {
	p {
		margin-bottom: 4px;
	}

	&__text {
		color: var(--color-error-text, var(--color-error));
	}

	&__details {
		margin-bottom: 12px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;

		summary {
			cursor: pointer;
		}

		code {
			display: block;
			margin-top: 4px;
			overflow-wrap: anywhere;
			white-space: pre-wrap;
		}
	}
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper not-found">
		<!-- a mistyped or stale deep link used to draw the sidebar and an
		     empty column, with nothing to say where the reader was -->
		<EmptyContent :item="item" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import EmptyContent from '../components/EmptyContent.vue'

export default {
	name: 'NotFound',

	components: {
		EmptyContent,
	},

	computed: {
		/** @return {object} what the empty state says, and the way home */
		item() {
			return {
				title: t('social', 'Page not found'),
				description: t('social', 'There is nothing at this address. The link may be wrong, or what it pointed to has moved.'),
				scene: 'tracks',
				action: {
					label: t('social', 'Back to my feed'),
					to: { name: 'timeline' },
				},
			}
		},
	},

	methods: {
		t,
	},
}
</script>

<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal v-if="open" :name="t('social', 'Keyboard shortcuts')" @close="$emit('close')">
		<div class="shortcuts">
			<h2>{{ t('social', 'Keyboard shortcuts') }}</h2>
			<ShortcutList />
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import { translate } from '@nextcloud/l10n'
import ShortcutList from './ShortcutList.vue'

/**
 * The `?` dialog: the shortcut list over whatever the reader was looking at.
 *
 * The list itself is `ShortcutList`, which the Settings page shows too — a
 * shortcut is a promise about a keystroke, and two places making that promise
 * must not be able to disagree about it.
 */
export default {
	name: 'ShortcutHelp',
	components: {
		NcModal,
		ShortcutList,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang="scss">
.shortcuts {
	padding: 24px 32px 32px;

	h2 {
		margin-bottom: 16px;
		font-size: 20px;
		font-weight: bold;
	}
}
</style>

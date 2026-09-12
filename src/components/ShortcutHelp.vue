<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal v-if="open" :name="t('social', 'Keyboard shortcuts')" @close="$emit('close')">
		<div class="shortcuts">
			<h2>{{ t('social', 'Keyboard shortcuts') }}</h2>
			<dl>
				<div v-for="shortcut in shortcuts" :key="shortcut.event" class="shortcuts__row">
					<dt>
						<kbd v-for="key in shortcut.keys" :key="key">{{ key }}</kbd>
					</dt>
					<dd>{{ shortcut.label }}</dd>
				</div>
			</dl>
			<p class="shortcuts__hint">
				{{ t('social', 'Shortcuts are off while you are writing.') }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import { translate } from '@nextcloud/l10n'
import { SHORTCUTS } from '../services/shortcuts.js'

export default {
	name: 'ShortcutHelp',
	components: {
		NcModal,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],
	computed: {
		shortcuts() {
			return SHORTCUTS
		},
	},

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

	&__row {
		display: flex;
		align-items: baseline;
		gap: 16px;
		padding: 6px 0;
		border-bottom: 1px solid var(--color-border);

		dt {
			flex: 0 0 96px;
			display: flex;
			gap: 4px;
		}

		dd {
			margin: 0;
		}
	}

	&__hint {
		margin-top: 16px;
		color: var(--color-text-maxcontrast);
	}

	kbd {
		display: inline-block;
		min-width: 20px;
		padding: 2px 6px;
		border: 1px solid var(--color-border-dark);
		border-bottom-width: 2px;
		border-radius: 4px;
		background: var(--color-background-dark);
		font-family: monospace;
		font-size: 12px;
		text-align: center;
	}
}
</style>

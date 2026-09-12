<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<img
		class="emoji"
		draggable="false"
		:alt="emoji"
		:src="emojiUrl">
</template>

<script>
import { generateFilePath } from '@nextcloud/router'
import { toCodePoint } from '../utils/emojiCodePoint.js'

// avoid using a string literal like '\u200D' here because minifiers expand it inline
const U200D = String.fromCharCode(0x200D)
const UFE0Fg = /\uFE0F/g

export default {
	name: 'Emoji',
	props: {
		emoji: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @return {string}
		 */
		icon() {
			return toCodePoint(this.emoji.indexOf(U200D) < 0
				? this.emoji.replace(UFE0Fg, '')
				: this.emoji)
		},

		/**
		 * @return {string}
		 */
		emojiUrl() {
			return generateFilePath('social', 'img', 'twemoji/' + this.icon + '.svg')
		},
	},
}
</script>

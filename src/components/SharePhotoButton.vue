<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="share-photo">
		<input
			ref="input"
			type="file"
			accept="image/*"
			multiple
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually"
			@change="onPicked">
		<NcButton variant="secondary" wide @click="pick">
			<template #icon>
				<ImagePlusOutline :size="20" />
			</template>
			{{ t('social', 'Share a photo') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ImagePlusOutline from 'vue-material-design-icons/ImagePlusOutline.vue'
import eventBus, { COMPOSE_MEDIA } from '../services/eventBus.js'

/**
 * What stands where the composer box stands on the other pages, on Photos.
 *
 * A post there starts with its picture: the device's picker opens first,
 * and the composer only once something has been chosen, with it attached.
 * The picker is opened from this button's own press because a browser only
 * lets a page open it in answer to one: a picker asked for later, once the
 * composer's code has arrived, may be refused.
 */
export default {
	name: 'SharePhotoButton',
	components: {
		ImagePlusOutline,
		NcButton,
	},

	methods: {
		t,

		pick() {
			/** @type {HTMLInputElement} */ (this.$refs.input).click()
		},

		/** @param {Event} event the picker's change, with what was chosen */
		onPicked(event) {
			const input = /** @type {HTMLInputElement} */ (event.target)
			const files = Array.from(input.files ?? [])
			// the input keeps its selection, so the same picture picked twice
			// in a row would otherwise be ignored the second time
			input.value = ''
			if (files.length > 0) {
				eventBus.emit(COMPOSE_MEDIA, { files })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.share-photo {
	max-width: var(--social-column);
	margin: 0 auto calc(var(--default-grid-baseline, 4px) * 3);
}
</style>

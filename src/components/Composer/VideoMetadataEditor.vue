<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="video-row">
		<NcTextField
			:modelValue="title"
			class="video-row__title"
			:label="t('social', 'Video title')"
			:placeholder="t('social', 'Video title — what it is called on other servers')"
			maxlength="120"
			@update:modelValue="$emit('update:title', $event)" />
		<div class="video-row__pair">
			<NcTextField
				:modelValue="category"
				class="video-row__field"
				:label="t('social', 'Category')"
				:placeholder="t('social', 'Category, e.g. Music')"
				maxlength="60"
				@update:modelValue="$emit('update:category', $event)" />
			<NcTextField
				:modelValue="licence"
				class="video-row__field"
				:label="t('social', 'Licence')"
				:placeholder="t('social', 'Licence, e.g. CC BY-SA')"
				maxlength="60"
				@update:modelValue="$emit('update:licence', $event)" />
		</div>
	</div>
</template>

<script>
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'VideoMetadataEditor',
	components: {
		NcTextField,
	},

	props: {
		title: {
			type: String,
			default: '',
		},

		category: {
			type: String,
			default: '',
		},

		licence: {
			type: String,
			default: '',
		},
	},

	emits: ['update:title', 'update:category', 'update:licence'],
	methods: {
		t,
	},
}
</script>

<style scoped>
.video-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block-end: 8px;
}

.video-row__pair {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.video-row__field {
	flex: 1 1 10em;
	min-width: 0;
}

.video-row__title {
	width: 100%;
}
</style>

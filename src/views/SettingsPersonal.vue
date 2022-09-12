<template>
	<SettingsSection :title="t('social', 'Social')" :description="t('social', 'Configure social .......')">
		<div class="form-field">
			<label>{{ t('social', 'Default post visibility:') }}</label>
			<CheckboxRadioSwitch :checked.sync="defaultVisibility"
				value="PUBLIC" name="style"
				type="radio">
				{{ t('social', 'Public') }}
			</CheckboxRadioSwitch>
			<p class="form-info-checkbox">
				{{ t('social', 'Make your post publicly visible') }}
			</p>

			<CheckboxRadioSwitch :checked.sync="defaultVisibility"
				value="INSTANCE" name="style"
				type="radio">
				{{ t('social', 'Instance Only') }}
			</CheckboxRadioSwitch>
			<p class="form-info-checkbox">
				{{ t('social', 'Make your post visible only to the user of this instance') }}
			</p>

			<CheckboxRadioSwitch :checked.sync="defaultVisibility"
				value="FOLLOWER" name="style"
				type="radio">
				{{ t('social', 'Followers only') }}
			</CheckboxRadioSwitch>
			<p class="form-info-checkbox">
				{{ t('social', 'Make your post visible only to your follower') }}
			</p>
		</div>

		<div class="form-field">
			<CheckboxRadioSwitch :checked.sync="style" value="text" name="style"
				type="switch">
				{{ t('social', 'Require follow requests') }}
			</CheckboxRadioSwitch>
			<p class="form-info-checkbox">
				{{ t('social', 'Manually control who can follow you by approving follow requests') }}
			</p>
		</div>
	</SettingsSection>
</template>

<script>

import CheckboxRadioSwitch from '@nextcloud/vue/dist/Components/CheckboxRadioSwitch'
import SettingsSection from '@nextcloud/vue/dist/Components/SettingsSection'

export default {
	name: 'SetupUser',
	components: {
		CheckboxRadioSwitch,
		SettingsSection
	},
	data() {
		return {
			defaultVisibility: 'PUBLIC'
		}
	}
}
</script>

<style scoped lang="scss">
.form-info-checkbox {
	padding-left: 26px;
	font-size: 14px;
	margin-top: -2px;
}

.form-info-field {
	font-size: 14px;
	margin-top: -2px;
}

.form-field {
	margin-top: 1.5rem;
}

.form-input {
	width: 300px;
	max-width: 100%;
	display: block;
}
</style>

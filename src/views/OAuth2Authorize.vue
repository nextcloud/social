<!--
 - @copyright 2022 Carl Schwan <carl@carlschwan.eu>
 - @license GNU AGPL version 3 or any later version
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -->

<template>
	<div class="wrapper">
		<form class="guest-box" method="post">
			<h1>{{ t('social', 'Authorization required') }}</h1>
			<p>
				{{ t('social', '{appDisplayName} would like permission to access your account. It is a third party application.', {appDisplayName: appName}) }}
				<b>{{ t('social', 'If you do not trust it, then you should not authorize it.') }}</b>
			</p>
			<div class="button-row">
				<NcButton type="primary" nativeType="submit">
					{{ t('social', 'Authorize') }}
				</NcButton>
				<NcButton type="error" :href="homeUrl">
					{{ t('social', 'Deny') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'OAuth2Authorize',
	components: {
		NcButton,
	},
	data() {
		return {
			appName: loadState('social', 'appName'),
		}
	},
	computed: {
		homeUrl() {
			generateUrl('/apps/social/')
		},
	},
}
</script>

<style lang="scss" scopped>
.wrapper {
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	width: 100%;
}
.guest-box {
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	padding: 1rem;
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
	display: inline-block;
	max-width: 600px;

	h1 {
		font-weight: bold;
		text-align: center;
		font-size: 20px;
		margin-bottom: 12px;
		line-height: 140%;
	}

	.button-row {
		display: flex;
		gap: 1rem;
		flex-direction: row;
		margin-top: 1rem;
		justify-content: end;
	}
}
</style>

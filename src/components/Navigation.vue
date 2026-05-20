<template>
	<NcAppNavigation>
		<template #search>
			<NcAppNavigationSearch
				v-model="localSearch"
				:label="t('social', 'Search …')"
				@update:modelValue="onSearchInput" />
		</template>
		<template #list>
			<NcAppNavigationItem
				:name="t('social', 'New post')"
				@click="showComposer = true">
				<template #icon>
					<IconPlus :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem v-if="hasErrors"
				:name="t('social', 'Errors')"
				:counter="errorCount"
				@click="showErrors = true">
				<template #icon>
					<IconAlertCircle class="error-icon" :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem v-for="item in menu.timelines"
				:key="item.key"
				:name="item.title"
				:active="isActive(item)"
				:counter="item.counter"
				@click="navigate(item)">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer />

			<NcAppNavigationItem
				:name="menu.profile.title"
				:active="isActive(menu.profile)"
				@click="navigate(menu.profile)">
				<template #icon>
					<NcAvatar :user="currentUser?.uid"
						:display-name="currentUser?.displayName"
						:size="20"
						:disable-tooltip="true"
						:disable-menu="true" />
				</template>
				<template #subname>
					<span class="navigation__subname">@{{ currentUser?.uid }}</span>
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<div class="navigation__footer">
				<NcAppNavigationSettings :name="t('social', 'Settings')">
					<NcAppNavigationItem :name="t('social', 'Reset local cache')"
						@click="$emit('reset-cache')">
						<template #icon>
							<IconDelete :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('social', 'Help &amp; documentation')"
						:href="'https://github.com/SchBenedikt/social/'"
						target="_blank">
						<template #icon>
							<IconHelpCircle :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationSettings>
			</div>
		</template>
	</NcAppNavigation>

	<NcModal v-if="showComposer"
		:name="t('social', 'New post')"
		@close="showComposer = false">
		<div class="modal-composer">
			<Composer />
		</div>
	</NcModal>

	<NcModal v-if="showErrors"
		:name="t('social', 'Errors')"
		@close="showErrors = false">
		<div class="modal-errors">
			<div v-for="error in appErrors" :key="error.id" class="modal-errors__item">
				<div class="modal-errors__title">{{ error.title }}</div>
				<div class="modal-errors__message">{{ error.message }}</div>
				<NcButton type="tertiary" @click="dismissError(error.id)">
					{{ t('social', 'Dismiss') }}
				</NcButton>
			</div>
			<NcButton v-if="appErrors.length > 1" type="tertiary" @click="clearAllErrors">
				{{ t('social', 'Dismiss all') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationSpacer from '@nextcloud/vue/components/NcAppNavigationSpacer'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'

import Composer from './Composer/Composer.vue'

import IconHome from 'vue-material-design-icons/Home.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconHelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import currentuserMixin from '../mixins/currentUserMixin.js'

export default {
	name: 'Navigation',
	components: {
		NcAppNavigation,
		NcAppNavigationSearch,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcAppNavigationSpacer,
		NcAppNavigationSettings,
		NcAvatar,
		NcModal,
		NcButton,
		Composer,
		IconHome,
		IconBell,
		IconCommentAccount,
		IconAccountMultiple,
		IconEarth,
		IconHeart,
		IconPlus,
		IconHelpCircle,
		IconDelete,
		IconAlertCircle,
	},
	mixins: [currentuserMixin],
	data() {
		return {
			localSearch: '',
			showComposer: false,
			showErrors: false,
		}
	},
	computed: {
		hasErrors() {
			return this.$store.getters.hasErrors
		},
		errorCount() {
			return this.$store.getters.appErrors.length
		},
		appErrors() {
			return this.$store.getters.appErrors
		},
		menu() {
			return {
				timelines: [
					{
						key: 'social-home',
						icon: IconHome,
						title: t('social', 'Home'),
						to: { name: 'timeline' },
					},
					{
						key: 'social-notifications',
						icon: IconBell,
						title: t('social', 'Notifications'),
						to: { name: 'timeline', params: { type: 'notifications' } },
						counter: '0',
					},
					{
						key: 'social-direct',
						icon: IconCommentAccount,
						title: t('social', 'Direct messages'),
						to: { name: 'timeline', params: { type: 'direct' } },
					},
					{
						key: 'social-local',
						icon: IconAccountMultiple,
						title: t('social', 'Local'),
						to: { name: 'timeline', params: { type: 'timeline' } },
					},
					{
						key: 'social-global',
						icon: IconEarth,
						title: t('social', 'Global'),
						to: { name: 'timeline', params: { type: 'federated' } },
					},
					{
						key: 'social-liked',
						icon: IconHeart,
						title: t('social', 'Liked posts'),
						to: { name: 'timeline', params: { type: 'favourites' } },
					},
				],
			profile: {
				key: 'social-profile',
				icon: 'user',
				title: t('social', 'Profile'),
				to: { name: 'profile', params: { account: this.currentUser?.uid } },
			},
			}
		},
	},
		methods: {
		dismissError(id) {
			this.$store.dispatch('dismissAppError', id)
		},
		clearAllErrors() {
			this.$store.commit('clearErrors')
		},
		onSearchInput() {
			this.$emit('search', this.localSearch)
		},
		isActive(item) {
			const route = this.$route
			const to = item.to
			if (route.name !== to.name && !route.name?.startsWith(to.name + '.')) return false
			for (const key of Object.keys(to.params || {})) {
				if (route.params[key] !== to.params[key]) return false
			}
			return Object.keys(to.params || {}).length === Object.keys(route.params || {}).length
		},
		navigate(item) {
			this.$router.push(item.to)
		},
	},
}
</script>

<style scoped lang="scss">
.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.modal-composer {
	padding: calc(var(--default-grid-baseline) * 4);
}

.modal-errors {
	padding: calc(var(--default-grid-baseline) * 4);

	&__item {
		padding: calc(var(--default-grid-baseline) * 2) 0;
		border-bottom: 1px solid var(--color-border);
	}

	&__title {
		font-weight: 700;
		margin-bottom: 4px;
	}

	&__message {
		color: var(--color-text-lighter);
		margin-bottom: 8px;
		word-break: break-word;
	}
}

.error-icon {
	color: var(--color-error);
}

:deep(.app-navigation-entry) {
	border-radius: 8px;
	margin: 2px 0;

	&:hover {
		background: var(--color-background-hover);
	}

	&.active {
		background: var(--color-background-dark);
	}
}
</style>

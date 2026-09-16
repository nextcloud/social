<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		class="post-content"
		:class="{ 'post-content--openable': postRoute !== null }"
		:data-social-status="item.id"
		:aria-label="postLabel"
		@click="onPostClick">
		<div class="post-header">
			<div class="post-author-wrapper" :title="item.account.acct">
				<router-link
					v-if="item.account"
					:to="{ name: 'profile',
						params: { account: item.account.acct },
					}">
					<span class="post-author">
						<DisplayName :text="item.account.display_name" :emojis="item.account.emojis" />
					</span>
					<!-- The handle is what the byline falls back to, not what it
					     carries: it repeats under the pointer as the wrapper's
					     own `title`, and again in the card that opens when the
					     avatar or the name is hovered. An account with no
					     display name has nothing else to be called, so there it
					     is the byline. -->
					<span v-if="!hasDisplayName" class="post-author-id">
						@{{ item.account.username }}
					</span>
					<span
						v-if="!origin.local"
						class="post-instance"
						:style="{ '--instance-colour': origin.colour }"
						:title="t('social', 'Posted from {instance}', { instance: origin.instance })">
						{{ origin.instance }}
					</span>
				</router-link>
			</div>
			<button
				:data-timestamp="timestamp"
				type="button"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</button>
			<span v-if="item.pinned" class="post-pinned" :title="t('social', 'Pinned post')">
				<Pin :size="14" />
				{{ t('social', 'Pinned') }}
			</span>
			<!-- the byline is 12px text; a 22px globe beside it read as the
			     loudest thing in the row, and it is the least important -->
			<VisibilityIcon
				v-if="visibility"
				:title="visibility.text"
				class="post-visibility"
				:size="14"
				:visibility="visibility.id" />
			<!-- where it was taken, when the poster said: a place is never
			     inferred, so this is only ever what somebody chose to say -->
			<router-link
				v-if="item.place && item.place.id"
				class="post-place"
				:to="{ name: 'place', params: { id: item.place.id } }"
				:title="t('social', 'Posts from {place}', { place: placeLabel })">
				<MapMarkerOutline :size="14" />
				<span class="post-place__name">{{ placeLabel }}</span>
			</router-link>
		</div>

		<!-- who is in the picture, when the poster named anybody. Names rather
		     than boxes drawn over the image: what is stored is a fact about the
		     post, and a rectangle is a thing no client of this network draws -->
		<p v-if="taggedPeople.length" class="post-tagged">
			<IconAccountBoxMultiple :size="14" />
			<span class="post-tagged__with">{{ t('social', 'With') }}</span>
			<router-link
				v-for="(person, index) in taggedPeople"
				:key="person.acct"
				class="post-tagged__person"
				:to="{ name: 'profile', params: { account: person.acct } }">
				{{ person.display_name || person.username }}<span v-if="index < taggedPeople.length - 1">,</span>
			</router-link>
			<!-- the whole remedy for being in somebody else's photograph:
			     leaving it, which needs nobody's permission -->
			<NcButton
				v-if="isTagged"
				variant="tertiary-no-background"
				class="post-tagged__leave"
				:disabled="untagging"
				@click="untagMe">
				{{ t('social', 'Remove me') }}
			</NcButton>
		</p>
		<div v-if="isEditing" class="post-edit-inline">
			<input
				v-model="editSpoiler"
				type="text"
				class="post-edit-warning"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')">
			<textarea
				ref="editInput"
				v-model="editContent"
				class="post-edit-textarea"
				:maxlength="maxLength"
				:aria-describedby="editIsTooLong ? `post-edit-count-${item.id}` : undefined"
				:placeholder="t('social', 'Edit your post')"
				@keydown.ctrl.enter="saveEdit" />
			<div class="post-edit-actions">
				<span
					:id="`post-edit-count-${item.id}`"
					class="post-edit-count"
					:class="{ 'post-edit-count--over': editIsTooLong }"
					role="status">
					{{ editCharactersLeftLabel }}
				</span>
				<NcButton
					variant="primary"
					:disabled="!editCanSave"
					:aria-label="t('social', 'Save')"
					@click="saveEdit">
					{{ t('social', 'Save') }}
				</NcButton>
				<NcButton
					:aria-label="t('social', 'Cancel')"
					@click="cancelEdit">
					{{ t('social', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		<!--
		  A filter the reader wrote themselves matched this post. Same shape as
		  the content warning below, and for the same reason: the body is not in
		  the page until it is asked for, so a word somebody filtered cannot be
		  read by accident on the way past.

		  Only `warn` filters reach this. A `hide` filter is applied by the
		  server, which never sends the status at all.

		  Lifting it falls through to the branches below rather than rendering
		  the body here, so a post carrying both a filter and a content warning
		  is still covered by the warning afterwards — the author's cover is not
		  the reader's to lift.
		-->
		<div v-else-if="filterCovers" class="post-filtered">
			<p class="post-filtered__reason">
				{{ filterLabel }}
			</p>
			<NcButton variant="secondary" @click="filterLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show anyway') }}
			</NcButton>
		</div>
		<!--
		  A content warning covers the post, not only its text: the pictures,
		  the poll and the link preview used to be siblings rendered
		  unconditionally, so the one thing the feature exists to prevent
		  happened anyway.
		-->
		<div v-else-if="hasSpoiler" class="post-warning">
			<p class="post-warning__text">
				{{ item.spoiler_text }}
			</p>
			<NcButton
				variant="secondary"
				:aria-expanded="warningLifted ? 'true' : 'false'"
				@click="warningLifted = !warningLifted">
				{{ warningLifted ? t('social', 'Show less') : t('social', 'Show more') }}
			</NcButton>
			<div v-if="warningLifted" class="post-message post-message--behind-warning">
				<MessageContent v-if="item.content" :item="displayedItem" />
			</div>
		</div>
		<!--
		  A post carrying pictures is read pictures first: they lead at the
		  width of the card and the text reads as their caption. A warned post
		  is not, because its cover has to come before anything it covers.
		-->
		<template v-else-if="mediaLeads">
			<PostAttachment
				v-if="mediaRevealed"
				mediaFirst
				:to="mediaRoute"
				:video="item.video"
				:attachments="item.media_attachments || []" />
			<div v-else class="post-sensitive post-sensitive--leading">
				<NcButton
					variant="secondary"
					@click="warningLifted = true">
					<template #icon>
						<EyeOff :size="20" />
					</template>
					{{ t('social', 'Show sensitive content') }}
				</NcButton>
			</div>
			<div v-if="item.content" class="post-message post-message--caption">
				<MessageContent :item="displayedItem" />
			</div>
		</template>
		<div v-else-if="item.content" class="post-message">
			<MessageContent :item="displayedItem" />
		</div>
		<!-- a translation is somebody else's words put through a machine, and
		     a reader is entitled to know that is what they are reading. Not
		     under a post a filter is covering: there is no body there to have
		     been translated -->
		<p v-if="translation !== null && !filterCovers" class="post-translated">
			{{ translatedFrom === ''
				? t('social', 'Translated by {provider}', { provider: translation.provider || t('social', 'this server') })
				: t('social', 'Translated from {language} by {provider}', {
					language: translatedFrom,
					provider: translation.provider || t('social', 'this server'),
				}) }}
		</p>
		<template v-if="mediaRevealed && !filterCovers">
			<QuotedPost v-if="item.quote" :quote="item.quote" />
			<Poll v-if="localPoll" :poll="localPoll" @update:poll="updatePoll" />
			<PostAttachment
				v-if="hasAttachments && !mediaLeads"
				:to="mediaRoute"
				:video="item.video"
				:attachments="item.media_attachments || []" />
			<PostCard v-if="showCard" :card="item.card" />
		</template>
		<!-- not when there is a content warning: that already renders a
		     "Show more" for the very same flag, so a post with both offered
		     two buttons for one reveal. No aria-expanded either — this
		     control is gone the moment it would have to say "true". And not
		     when the media leads, which shows this same reveal in the place
		     the pictures will take. -->
		<div v-else-if="!hasSpoiler && !mediaLeads && !filterCovers" class="post-sensitive">
			<NcButton
				variant="secondary"
				@click="warningLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show sensitive content') }}
			</NcButton>
		</div>
		<!-- The reactions stay on the card rather than joining the row below:
		     that row is revealed by the pointer, and a reaction somebody left
		     is something to be seen without hovering. -->
		<ReactionBar
			v-if="$route && $route.params.type !== 'notifications'"
			:statusId="String(item.id || '')"
			:modelValue="item.reactions || []"
			:canReact="!serverData.public"
			@update:modelValue="onReactionsChanged" />
		<!-- The row is revealed by the pointer and the card grows to make
		     room for it. The grid row going from 0fr to 1fr is the one way
		     to animate to a height nobody can know in advance, and the
		     dialogs below stay outside it: a box collapsing to nothing is
		     no place to put a modal. -->
		<div
			v-if="$route && $route.params.type !== 'notifications' && !serverData.public"
			class="post-actions-reveal"
			:class="{ 'post-actions-reveal--held': menuOpen }">
			<div class="post-actions">
				<!-- everything but the menu lives in the rail, which is what
				     widens; the menu is the pill at rest and never moves -->
				<div class="post-actions__rail">
					<div class="post-actions__groups">
						<div class="post-action-group">
							<NcButton
								:title="t('social', 'Reply')"
								:aria-label="t('social', 'Reply')"
								variant="tertiary"
								@click="reply">
								<template #icon>
									<Reply :size="20" />
								</template>
							</NcButton>
							<RollingCount :count="item.replies_count || 0" />
						</div>
						<div
							class="post-action-group"
							:class="{ 'post-action-group--refused': refused === 'boost' }">
							<NcButton
								v-if="item.visibility === 'public' || item.visibility === 'unlisted'"
								:title="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
								:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
								:aria-pressed="isBoosted ? 'true' : 'false'"
								variant="tertiary"
								:class="{ 'post-action--spun': celebrate === 'boost' }"
								@click="boost">
								<template #icon>
									<Repeat :size="20" :fillColor="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
								</template>
							</NcButton>
							<RollingCount :count="item.reblogs_count || 0" />
						</div>
						<div
							class="post-action-group post-action-group--like"
							:class="{ 'post-action-group--refused': refused === 'like' }">
							<span v-if="celebrate === 'like'" class="post-action__burst" aria-hidden="true" />
							<!-- one button whose label changes, not two swapped by v-if:
							     unmounting the button someone just pressed drops their focus
							     to the body and loses their place in the timeline -->
							<NcButton
								:title="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
								:aria-label="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
								:aria-pressed="isLiked ? 'true' : 'false'"
								variant="tertiary"
								:class="{ 'post-action--popped': isLiked && celebrate === 'like' }"
								@click="like">
								<template #icon>
									<Heart v-if="isLiked" :size="20" fillColor="var(--color-element-error)" />
									<HeartOutline v-else :size="20" />
								</template>
							</NcButton>
							<RollingCount :count="item.favourites_count || 0" />
						</div>
						<!-- only ever on the author's own copy: the server sends
						     `view_count` as null on everybody else's, because how
						     many people read a post is the author's business -->
						<div
							v-if="item.view_count !== null && item.view_count !== undefined"
							class="post-action post-action--views"
							:title="n('social', '%n account here opened this post', '%n accounts here opened this post', item.view_count)">
							<IconEyeOutline :size="20" />
							<RollingCount :count="item.view_count" />
						</div>
					</div>
				</div>
				<!-- the menu opens in a portal, so the pointer leaving the card
				     while it is open would take the row it belongs to away -->
				<NcActions @update:open="menuOpen = $event">
					<NcActionButton v-if="canQuote" @click="quote">
						<template #icon>
							<FormatQuoteClose :size="20" />
						</template>
						{{ t('social', 'Quote') }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct"
						icon="icon-rename"
						@click="editPost">
						{{ t('social', 'Edit') }}
					</NcActionButton>
					<!-- the answer to "this no longer belongs on my profile" that
					     is not destroying it. Nothing federates: the post stays on
					     every server that received it, which is what deleting is
					     for -->
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct && item.local !== false"
						:disabled="archiving"
						closeAfterClick
						@click="toggleArchive">
						<template #icon>
							<IconArchiveOutline :size="20" />
						</template>
						{{ item.archived ? t('social', 'Put back on my profile') : t('social', 'Archive') }}
					</NcActionButton>
					<!-- who may quote it, and who already has. The two are
					     deliberately one dialog: the reason to let people quote
					     you is the same reason to be able to stop one of them -->
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct && item.local !== false"
						closeAfterClick
						@click="managingQuotes = true">
						<template #icon>
							<FormatQuoteClose :size="20" />
						</template>
						{{ t('social', 'Quotes of this post') }}
					</NcActionButton>
					<!-- who is in the picture, which only the author may say:
					     anybody able to write a name onto anybody's photograph
					     could put a post in front of an audience that did not
					     ask for it -->
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct && hasPictures"
						closeAfterClick
						@click="taggingPeople = true">
						<template #icon>
							<IconAccountBoxMultiple :size="20" />
						</template>
						{{ t('social', 'Tag people') }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct"
						icon="icon-delete"
						@click="askToDelete(false)">
						{{ t('social', 'Delete') }}
					</NcActionButton>
					<!-- the correction people actually make: the post goes and
					     its words come back in the composer, to be posted again
					     as a new post -->
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct"
						@click="askToDelete(true)">
						<template #icon>
							<PencilBoxOutline :size="20" />
						</template>
						{{ t('social', 'Delete & re-draft') }}
					</NcActionButton>
					<NcActionButton
						v-if="canTranslate"
						:disabled="translating"
						closeAfterClick
						@click="toggleTranslation">
						<template #icon>
							<Translate :size="20" />
						</template>
						{{ translation === null
							? t('social', 'Translate')
							: t('social', 'Show original') }}
					</NcActionButton>
					<!-- where the post got to: the queue knows, and this asks it
					     for the author, who is the only one it is answered for -->
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct && item.local !== false"
						@click="openDelivery">
						<template #icon>
							<SendCheck :size="20" />
						</template>
						{{ t('social', 'Delivery status') }}
					</NcActionButton>
					<!-- NcActionLink sets rel="nofollow noreferrer noopener" itself -->
					<NcActionLink
						v-if="!origin.local && item.url"
						:href="item.url"
						target="_blank">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('social', 'Open on original instance') }}
					</NcActionLink>
					<NcActionButton @click="toggleBookmark">
						<template #icon>
							<Bookmark v-if="item.bookmarked" :size="20" />
							<BookmarkOutline v-else :size="20" />
						</template>
						{{ item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark') }}
					</NcActionButton>
					<!-- an album is made of the reader's own pictures; where the
					     picture is, is where it is put into one -->
					<NcActionButton
						v-if="canCollect"
						@click="showCollectionDialog = true">
						<template #icon>
							<FolderMultiplePlusOutline :size="20" />
						</template>
						{{ t('social', 'Add to a collection') }}
					</NcActionButton>
					<NcActionButton
						v-if="canPin"
						@click="togglePin">
						<template #icon>
							<Pin v-if="!item.pinned" :size="20" />
							<PinOff v-else :size="20" />
						</template>
						{{ item.pinned ? t('social', 'Unpin from profile') : t('social', 'Pin to profile') }}
					</NcActionButton>
					<!-- what to do about somebody else, from the post that
					     made the reader want to: both take their posts out of
					     every timeline at once -->
					<NcActionButton v-if="canModerateAuthor" @click="showMuteDialog = true">
						<template #icon>
							<VolumeOff :size="20" />
						</template>
						{{ t('social', 'Mute {account}', { account: item.account.acct }) }}
					</NcActionButton>
					<NcActionButton v-if="canModerateAuthor" @click="showBlockDialog = true">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('social', 'Block {account}', { account: item.account.acct }) }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct !== currentAccount?.acct"
						@click="showReportDialog = true">
						<template #icon>
							<Flag :size="20" />
						</template>
						{{ t('social', 'Report') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>
		<MuteDialog
			v-if="showMuteDialog"
			v-model:open="showMuteDialog"
			:account="item.account" />
		<CollectionPickerDialog
			v-if="showCollectionDialog"
			v-model:open="showCollectionDialog"
			:status="item" />
		<TagPeopleDialog
			v-if="taggingPeople"
			:nid="item.nid"
			:people="taggedPeople"
			@close="taggingPeople = false"
			@tagged="onTagged" />
		<QuoteControlDialog
			v-if="managingQuotes"
			:nid="item.nid"
			:approval="item.quote_approval"
			@close="managingQuotes = false" />
		<NcDialog
			v-model:open="showBlockDialog"
			:name="t('social', 'Block {account}?', { account: item.account.acct })"
			:buttons="blockButtons">
			<p class="report-hint">
				{{ t('social', 'Their posts leave your timelines, they are unfollowed both ways, and they can no longer follow you or see your posts.') }}
			</p>
		</NcDialog>
		<NcDialog
			v-model:open="showReportDialog"
			:name="t('social', 'Report {account}', { account: item.account.acct })"
			:buttons="reportButtons">
			<p class="report-hint">
				{{ t('social', 'The report goes to the moderators of this instance. It is never sent to the reported account or their server.') }}
			</p>
			<textarea
				v-model="reportComment"
				class="report-comment"
				:placeholder="t('social', 'Why are you reporting this post? (optional)')"
				rows="3" />
		</NcDialog>
		<NcDialog
			v-model:open="showDeliveryDialog"
			:name="t('social', 'Delivery status')"
			:buttons="deliveryButtons"
			class="delivery-dialog">
			<p v-if="deliveryLoading" class="delivery-hint">
				{{ t('social', 'Asking the delivery queue …') }}
			</p>
			<p v-else-if="deliveryError" class="delivery-hint delivery-hint--error">
				{{ deliveryError }}
			</p>
			<template v-else-if="delivery">
				<p class="delivery-hint">
					{{ deliverySummary }}
				</p>
				<ul v-if="delivery.instances.length" class="delivery-list">
					<li
						v-for="entry in delivery.instances"
						:key="entry.host + entry.state + entry.last"
						class="delivery-list__row"
						:class="'delivery-list__row--' + entry.state">
						<span class="delivery-list__dot" aria-hidden="true" />
						<span class="delivery-list__host">{{ entry.host }}</span>
						<span class="delivery-list__state">{{ deliveryStateLabel(entry) }}</span>
					</li>
				</ul>
				<p v-else class="delivery-hint delivery-hint--muted">
					{{ t('social', 'Nothing is on record for this post. Deliveries are kept for {days} days; a post older than that, or one that never left this server, has nothing to show.', { days: retentionDays }) }}
				</p>
			</template>
		</NcDialog>
		<!-- deleting is irreversible and federates: it is not something to
		     do on the first click of a menu item sitting under "Edit" -->
		<NcDialog
			v-model:open="showDeleteDialog"
			:name="deleteToRedraft ? t('social', 'Delete and write it again?') : t('social', 'Delete this post?')"
			:buttons="deleteButtons">
			<p class="delete-hint">
				{{ deleteToRedraft
					? t('social', 'The post is removed everywhere it reached, and its words, pictures and content warning are put back in the composer. What you post next is a new post: the boosts, likes and replies this one collected stay with it and are gone.')
					: t('social', 'The post is removed from this server and a deletion is sent to every server that received it. This cannot be undone.') }}
			</p>
		</NcDialog>
	</article>
</template>

<script>

// side-effect imports: they register the mention plugin and the string
// interface that the rendered content relies on
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import 'linkify-plugin-mention'
import 'linkify-string'
import PostAttachment from './PostAttachment.vue'
import PostCard from './PostCard.vue'
import ReactionBar from './ReactionBar.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Flag from 'vue-material-design-icons/Flag.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import IconEyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import PencilBoxOutline from 'vue-material-design-icons/PencilBoxOutline.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import SendCheck from 'vue-material-design-icons/SendCheck.vue'
import Translate from 'vue-material-design-icons/Translate.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import FolderMultiplePlusOutline from 'vue-material-design-icons/FolderMultiplePlusOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../services/toast.js'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { onTick } from '../services/clock.js'
import { originOf } from '../utils/instanceIdentity.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import QuotedPost from './QuotedPost.vue'
import RollingCount from './RollingCount.vue'
import DisplayName from './DisplayName.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import { mapStores } from 'pinia'
import { htmlToPlainText } from '../utils/plainText.js'
import { defaultLanguage, languageName } from '../utils/postLanguage.js'
import { useAccountStore } from '../store/account.js'
import { useInstanceStore } from '../store/instance.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'
import { defineAsyncComponent } from 'vue'

// The mute dialog is the same one the profile opens, and it is worth nothing
// until somebody asks for it: the post menu is on every post on the page.
const MuteDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./MuteDialog.vue'))
// fetched with the other dialogs a post rarely opens, for the same reason
const CollectionPickerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./CollectionPickerDialog.vue'))
// and the same for naming the people in a photograph, which is a thing an
// author does once per post and no reader ever does
const TagPeopleDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./TagPeopleDialog.vue'))
// same chunk, and for the same reason: a dialog nobody opens until they ask for
// it, which brings framework form controls with it
const QuoteControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./QuoteControlDialog.vue'))

export default {
	name: 'TimelinePost',
	components: {
		IconAccountBoxMultiple,
		QuoteControlDialog,
		TagPeopleDialog,
		IconArchiveOutline,
		IconEyeOutline,
		Cancel,
		CollectionPickerDialog,
		FolderMultiplePlusOutline,
		MapMarkerOutline,
		MuteDialog,
		ReactionBar,
		VolumeOff,
		PostAttachment,
		PostCard,
		NcActions,
		NcActionButton,
		NcActionLink,
		NcDialog,
		SendCheck,
		EyeOff,
		OpenInNew,
		Flag,
		NcButton,
		Bookmark,
		BookmarkOutline,
		PencilBoxOutline,
		Pin,
		PinOff,
		Translate,
		FormatQuoteClose,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
		Poll,
		QuotedPost,
		RollingCount,
		DisplayName,
		VisibilityIcon,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},

		type: {
			type: String,
			required: true,
		},
	},

	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},

	data() {
		return {
			isEditing: false,
			/** which action is playing its confirmation, '' when none */
			celebrate: '',
			/** which action the server refused, so the button can say so */
			refused: '',
			/** whether j/k has this post, so l/b/r act on the right one */
			hasKeyboardFocus: false,
			/** whether the overflow menu is open, which holds the action row open with it */
			menuOpen: false,
			/** a warned post stays closed until the reader opens it */
			warningLifted: false,
			/** and so does one the reader's own keyword filters matched */
			filterLifted: false,
			editContent: '',
			editSpoiler: '',
			showReportDialog: false,
			showMuteDialog: false,
			showCollectionDialog: false,
			showBlockDialog: false,
			showDeleteDialog: false,
			archiving: false,
			untagging: false,
			taggingPeople: false,
			managingQuotes: false,
			/** whether the delete on screen is the first half of a re-draft */
			deleteToRedraft: false,
			/** the Translation entity once it has arrived, null before */
			translation: null,
			/** whether the provider is working on it right now */
			translating: false,
			showDeliveryDialog: false,
			/** the answer of /statuses/{nid}/delivery, or null before it came */
			delivery: null,
			deliveryLoading: false,
			deliveryError: '',
			reportComment: '',
			localPoll: this.item?.poll ?? null,
			/** re-read from the shared clock, so "5 minutes ago" stays true */
			now: Date.now(),
		}
	},

	computed: {
		...mapStores(useAccountStore, useInstanceStore, useTimelineStore),

		/** @return {number} what the server accepts in one status, as the composer shows it */
		maxLength() {
			return this.instanceStore.maxCharacters
		},

		/**
		 * Whether the byline has a name of its own to show.
		 *
		 * Trimmed rather than tested for truth: a display name of one space is
		 * a name a remote server will happily federate, and it would draw a
		 * byline that is blank rather than one that falls back.
		 *
		 * @return {boolean}
		 */
		hasDisplayName() {
			return (this.item.account?.display_name ?? '').trim() !== ''
		},

		/**
		 * Where this post lives, or `null` when the reader is already there.
		 *
		 * A post in a timeline is a link to itself: a press anywhere on it
		 * that is not a link or a button opens it with its replies, and its
		 * pictures and videos open from there. The post whose page this is
		 * behaves the other way round — the picture opens full size, the video
		 * plays — because that is what the reader came for.
		 *
		 * A reply on that page is another post, so it links to its own page
		 * like any other.
		 *
		 * @return {object|null}
		 */
		postRoute() {
			if (!this.item?.account?.acct || this.item?.id === undefined) {
				return null
			}

			const isTheOneBeingRead = this.$route?.name === 'single-post'
				&& String(this.$route.params?.id) === String(this.item.id)

			return isTheOneBeingRead
				? null
				: {
						name: 'single-post',
						params: {
						// acct, not username: two remote accounts can share a
						// username, and the route has to name one of them
							account: this.item.account.acct,
							id: this.item.id,
							type: 'single-post',
						},
					}
		},

		/** @return {object|null} where a press on the media goes */
		mediaRoute() {
			return this.postRoute
		},

		/**
		 * @return {boolean} whether a keyword filter of the reader's own covers
		 * the post. Only `warn` filters reach a client — a status a `hide`
		 * filter matched is never sent — so anything matched is covered here
		 * rather than dropped.
		 */
		filterCovers() {
			return !this.filterLifted && matchedFilters(this.item).length > 0
		},

		/** @return {string} what the cover says, which is where to go to change it */
		filterLabel() {
			return filterCoverLabel(this.item)
		},

		/** @return {boolean} the author asked for the post to be covered */
		hasSpoiler() {
			return Boolean(this.item.spoiler_text)
		},

		/** @return {boolean} anything a warning is supposed to cover */
		hasMedia() {
			return this.hasAttachments || this.localPoll !== null || this.showCard || this.hasQuote
		},

		/** @return {boolean} the post embeds another one, whatever came of it */
		hasQuote() {
			return Boolean(this.item.quote)
		},

		/**
		 * @return {boolean} whether the media sits behind a reveal. A warning
		 * covers the whole post; `sensitive` on its own covers only the media,
		 * which is what Mastodon shows for a post flagged without a warning.
		 */
		hasGatedMedia() {
			return (this.hasSpoiler || this.item.sensitive === true) && this.hasMedia
		},

		/** @return {boolean} */
		mediaRevealed() {
			return !this.hasGatedMedia || this.warningLifted
		},

		/**
		 * @return {boolean} whether the post is laid out around its pictures.
		 * Not while it is being edited, where the text is the thing being
		 * worked on, and not under a content warning, which owns the top of
		 * the post until the reader lifts it.
		 */
		mediaLeads() {
			return this.hasAttachments && !this.hasSpoiler && !this.isEditing
		},

		/** @return {number} how many characters the edit has left */
		editCharsLeft() {
			return this.maxLength - this.editContent.length
		},

		/** @return {boolean} */
		editIsTooLong() {
			return this.editCharsLeft < 0
		},

		/** @return {boolean} */
		editCanSave() {
			return this.editContent.trim() !== '' && !this.editIsTooLong
		},

		/** @return {string} */
		editCharactersLeftLabel() {
			return this.editIsTooLong
				? n('social', '%n character too many', '%n characters too many', -this.editCharsLeft)
				: n('social', '%n character left', '%n characters left', this.editCharsLeft)
		},

		/** Who wrote it, so moving between posts by landmark says something. */
		postLabel() {
			return t('social', 'Post by {account}', { account: this.item.account?.acct ?? '' })
		},

		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account?.acct ?? '')
		},

		/** @return {boolean} a link preview replaces nothing, so media wins */
		showCard() {
			return !this.hasAttachments && Boolean(this.item.card?.title)
		},

		/**
		 * @return {boolean} own local posts can be pinned to the profile, and
		 * only the ones anyone may see: a pinned followers-only post was
		 * served in full to the anonymous internet through the featured
		 * collection, so the server now refuses anything that is not public
		 * or unlisted — the same set a boost is allowed for.
		 */
		canPin() {
			return this.item.account.acct === this.currentAccount?.acct
				&& this.item.local !== false
				&& (this.item.visibility === 'public' || this.item.visibility === 'unlisted')
		},

		/** @return {boolean} whether there is a picture to name anybody in */
		hasPictures() {
			return (this.item.media_attachments ?? []).length > 0
		},

		/** @return {Array} the people the poster named in this post's pictures */
		taggedPeople() {
			return this.item.tagged_people ?? []
		},

		/** @return {boolean} whether the reader is one of them */
		isTagged() {
			const me = this.currentAccount?.acct
			return Boolean(me) && this.taggedPeople.some((person) => person.acct === me)
		},

		/** @return {string} the place, with its country where one was given */
		placeLabel() {
			const place = this.item.place
			if (!place) {
				return ''
			}

			return place.country ? `${place.name}, ${place.country}` : place.name
		},

		/**
		 * @return {boolean} whether this post can go into one of the reader's
		 * collections: their own, written here, and with a picture or a video
		 * in it — a collection holds only its owner's own media posts, so
		 * offering the action on anything else would be offering a refusal
		 */
		canCollect() {
			return this.item.account.acct === this.currentAccount?.acct
				&& this.item.local !== false
				&& Array.isArray(this.item.media_attachments)
				&& this.item.media_attachments.length > 0
		},

		/**
		 * @return {boolean} whether this post may be quoted at all. A quote
		 * carries the audience of the quoter, so the server grants one only for
		 * a public or unlisted post — the same set a boost is allowed for — and
		 * offering the action on anything narrower would be offering a refusal.
		 */
		canQuote() {
			return this.item.visibility === 'public' || this.item.visibility === 'unlisted'
		},

		/**
		 * @return {boolean} whether this post's author is somebody the reader
		 * can act on: not themselves, and not on the public pages, where there
		 * is nobody signed in to do the blocking
		 */
		canModerateAuthor() {
			return !this.serverData.public
				&& !!this.currentAccount
				&& this.item.account.acct !== this.currentAccount?.acct
		},

		blockButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showBlockDialog = false
					},
				},
				{
					label: t('social', 'Block'),
					variant: 'error',
					callback: () => this.blockAuthor(),
				},
			]
		},

		reportButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showReportDialog = false
					},
				},
				{
					label: t('social', 'Report'),
					variant: 'error',
					callback: () => this.sendReport(),
				},
			]
		},

		deliveryButtons() {
			return [
				{
					label: t('social', 'Close'),
					callback: () => {
						this.showDeliveryDialog = false
					},
				},
			]
		},

		/** @return {number} how many days the queue keeps a finished delivery */
		retentionDays() {
			return Math.round((this.delivery?.retention ?? 7 * 86400) / 86400)
		},

		/**
		 * @return {string} the counts as one sentence — the author reads this
		 * line and, most of the time, needs nothing under it
		 */
		deliverySummary() {
			const d = this.delivery
			if (!d || d.total === 0) {
				return ''
			}
			const parts = []
			if (d.delivered) {
				parts.push(n('social', 'delivered to %n server', 'delivered to %n servers', d.delivered))
			}
			if (d.sending) {
				parts.push(n('social', 'being sent to %n server', 'being sent to %n servers', d.sending))
			}
			if (d.waiting) {
				parts.push(n('social', 'waiting for %n server', 'waiting for %n servers', d.waiting))
			}
			if (d.failing) {
				parts.push(n('social', 'failing against %n server', 'failing against %n servers', d.failing))
			}
			if (d.abandoned) {
				parts.push(n('social', 'given up on %n server', 'given up on %n servers', d.abandoned))
			}
			return t('social', 'Of {total}: {parts}.', { total: n('social', '%n delivery', '%n deliveries', d.total), parts: parts.join(', ') })
		},

		/**
		 * The post as it is shown: the translation when one has been asked for
		 * and arrived, the post itself otherwise.
		 *
		 * A copy rather than a write into the store's object: the translation
		 * belongs to this reader looking at this card, and writing it into the
		 * status would put it on every other card showing the same post and
		 * leave it there after a refresh.
		 *
		 * @return {object}
		 */
		displayedItem() {
			if (this.translation === null) {
				return this.item
			}

			return {
				...this.item,
				content: this.translation.content || this.item.content,
				spoiler_text: this.translation.spoiler_text || this.item.spoiler_text,
			}
		},

		/**
		 * Whether to offer a translation: only where the server has a provider,
		 * and only for a post written in another language than the reader's.
		 *
		 * A post with no language is not offered either. The server stores what
		 * the author declared and nothing else — guessing here would offer to
		 * translate English into English for every post that arrived without a
		 * `contentMap`.
		 *
		 * @return {boolean}
		 */
		canTranslate() {
			return this.instanceStore.translation
				&& Boolean(this.item.content)
				&& Boolean(this.item.language)
				&& this.item.language !== defaultLanguage()
		},

		/** @return {string} what the translation says it was translated from */
		translatedFrom() {
			const code = this.translation?.detected_source_language || this.item.language || ''

			return code === '' || code === 'und' ? '' : languageName(code)
		},

		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showDeleteDialog = false
					},
				},
				{
					label: this.deleteToRedraft ? t('social', 'Delete & re-draft') : t('social', 'Delete'),
					variant: 'error',
					callback: () => this.remove(),
				},
			]
		},

		/**
		 * @return {string}
		 */
		relativeTimestamp() {
			return fromNow(this.item.created_at, new Date(this.now))
		},

		/**
		 * @return {string}
		 */
		formattedDate() {
			return fullDateTime(this.item.created_at)
		},

		/**
		 * @return {number}
		 */
		timestamp() {
			return Date.parse(this.item.created_at)
		},

		/**
		 * @return {boolean}
		 */
		hasAttachments() {
			// TODO: clean media_attachments
			return (this.item.media_attachments || []).length > 0
		},

		/**
		 * @return {boolean}
		 */
		isBoosted() {
			return this.item.reblogged === true
		},
		/**
		 * @return {boolean}
		 */

		isLiked() {
			return this.item.favourited === true
		},

		/**
		 * @return {object}
		 */
		richParameters() {
			return {}
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.item.account.acct.includes('@')
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {object} */
		visibility() {
			return visibilitiesInfo.find(({ id }) => this.item.visibility === id)
		},
	},

	watch: {
		// a vote cast elsewhere (or reloaded from the server) has to reach the
		// copy this component renders, or navigating back shows the poll unvoted
		'item.poll': function(poll) {
			this.localPoll = poll ?? null
		},
	},

	mounted() {
		eventBus.on('timeline:focused', this.rememberFocus)
		eventBus.on('shortcut:like', this.likeIfFocused)
		eventBus.on('shortcut:boost', this.boostIfFocused)
		eventBus.on('shortcut:reply', this.replyIfFocused)
		eventBus.on('shortcut:open', this.openIfFocused)
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		eventBus.off('timeline:focused', this.rememberFocus)
		eventBus.off('shortcut:like', this.likeIfFocused)
		eventBus.off('shortcut:boost', this.boostIfFocused)
		eventBus.off('shortcut:reply', this.replyIfFocused)
		eventBus.off('shortcut:open', this.openIfFocused)
		this.stopTicking?.()
	},

	methods: {
		/**
		 * Puts the post away, or brings it back.
		 *
		 * The row leaves the timeline it is in as soon as the server agrees:
		 * the post is out of every list this server builds, and the list the
		 * reader is looking at is one of them.
		 *
		 * @return {Promise<void>}
		 */
		/**
		 * @param {Array} people who the post names now, as the server says
		 */
		onTagged(people) {
			this.timelineStore.updateStatusTagged({ statusId: this.item.id, taggedPeople: people })
		},

		/**
		 * Takes the reader's own name off this photograph.
		 *
		 * @return {Promise<void>}
		 */
		async untagMe() {
			if (this.untagging) {
				return
			}

			this.untagging = true
			try {
				const url = generateUrl('apps/social/api/v1.1/compose/tag/untagme')
				await axios.post(url, { status_id: this.item.nid })
				const me = this.currentAccount?.acct
				const left = this.taggedPeople.filter((person) => person.acct !== me)
				this.timelineStore.updateStatusTagged({ statusId: this.item.id, taggedPeople: left })
				showSuccess(t('social', 'Your name is off this photo.'))
			} catch (error) {
				logger.error('could not take a name off a photo', { error })
				showError(t('social', 'Could not remove your name'))
			} finally {
				this.untagging = false
			}
		},

		async toggleArchive() {
			if (this.archiving) {
				return
			}

			this.archiving = true
			const archived = this.item.archived === true
			try {
				await axios.post(generateUrl('apps/social/api/pixelfed/v1/archive/' + (archived ? 'remove' : 'add') + '/' + this.item.id))
				if (archived) {
					this.timelineStore.updateStatusArchived({ statusId: this.item.id, archived: false })
					showSuccess(t('social', 'The post is back on your profile'))
				} else {
					this.timelineStore.removeStatus(this.item)
					showSuccess(t('social', 'Archived. It is off your profile and out of the timelines; nobody else was told.'))
				}
			} catch (error) {
				logger.error('Failed to archive a post', { error })
				showError(t('social', 'Could not archive the post'))
			} finally {
				this.archiving = false
			}
		},

		/**
		 * The reaction bar came back from the server after a press. It goes to
		 * the store rather than onto this card, so the same post shown twice —
		 * a thread and the timeline behind it — cannot end up with two
		 * different bars.
		 *
		 * @param {Array<{name: string, count: number, me: boolean}>} reactions the bar
		 */
		onReactionsChanged(reactions) {
			this.timelineStore.updateStatusReactions({ statusId: this.item.id, reactions })
		},

		/**
		 * @param {import('../types/Mastodon.js').Status} status the post the keyboard moved to
		 */
		rememberFocus(status) {
			this.hasKeyboardFocus = status?.id === this.item.id
		},

		likeIfFocused() {
			if (this.hasKeyboardFocus) {
				this.like()
			}
		},

		boostIfFocused() {
			if (this.hasKeyboardFocus && (this.item.visibility === 'public' || this.item.visibility === 'unlisted')) {
				this.boost()
			}
		},

		replyIfFocused() {
			if (this.hasKeyboardFocus) {
				this.reply()
			}
		},

		openIfFocused() {
			if (this.hasKeyboardFocus) {
				this.getSinglePostTimeline()
			}
		},

		/**
		 * @function getSinglePostTimeline
		 * @description Opens the conversation the post belongs to.
		 *
		 * Remote posts used to return here with a logger.warn, which made the
		 * timestamp — the only affordance for opening a thread — silently dead
		 * on the Global and Federated timelines. The server serves the context
		 * of any status it has (`/api/v1/statuses/{nid}/context`), local or not.
		 */
		/**
		 * A press somewhere on the post that was not meant for something else.
		 *
		 * The whole card opens the post, the way a row in a list opens the
		 * thing it stands for.
		 *
		 * Everything interactive inside a post keeps its press: a mention, a
		 * hashtag, a link somebody wrote, the action buttons, the poll, the
		 * author's name, the media — which has a handler of its own that routes
		 * to the same place anyway. What is left is the body of the card, and
		 * pressing that opens the post.
		 *
		 * A selection is left alone as well: dragging across a post to copy a
		 * sentence ends in a click, and navigating away from what somebody has
		 * just highlighted is the worst possible answer to it.
		 *
		 * @param {MouseEvent} event the press
		 */
		onPostClick(event) {
			if (this.postRoute === null || event.defaultPrevented || event.button !== 0) {
				return
			}
			// a modified click is the reader asking for a tab or a window, and
			// the card is not a link, so there is nothing to hand them
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			if (event.target?.closest?.('a, button, input, textarea, select, label, video, audio, [role="button"], .post-actions, .v-popper')) {
				return
			}
			if ((window.getSelection?.()?.toString() ?? '') !== '') {
				return
			}

			this.$router.push(this.postRoute)
		},

		getSinglePostTimeline() {
			if (!this.item.account?.acct || this.item.id === undefined) {
				logger.warn('Cannot open a post without an account and an id', { post: this.item })
				return
			}

			this.$router.push({
				name: 'single-post',
				params: {
					// acct, not username: two remote accounts can share a
					// username, and the route has to name one of them
					account: this.item.account.acct,
					id: this.item.id,
					type: 'single-post',
				},
			})
		},

		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},

		reply() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-reply', this.item)
		},

		quote() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-quote', this.item)
		},

		/**
		 * Blocks the author, once the dialog has agreed. The store takes their
		 * posts out of every timeline, this one included, so there is nothing
		 * left here to say afterwards.
		 */
		async blockAuthor() {
			const relationship = await this.accountStore.blockAccount({ id: this.item.account.id })
			// the store said what went wrong; the dialog stays for another try
			if (relationship?.id) {
				this.showBlockDialog = false
				showSuccess(t('social', 'You have blocked {account}', { account: this.item.account.acct }))
			}
		},

		async sendReport() {
			try {
				await axios.post(generateUrl('apps/social/api/v1/reports'), {
					account_id: this.item.account.id,
					status_ids: [this.item.id],
					comment: this.reportComment,
				})
				showSuccess(t('social', 'Post reported to the moderators'))
				this.showReportDialog = false
				this.reportComment = ''
			} catch (error) {
				logger.error('Failed to report the post', { error })
				showError(t('social', 'Failed to report the post'))
			}
		},

		async boost() {
			const undo = this.isBoosted
			await this.act('boost', undo ? 'postUnBoost' : 'postBoost', !undo)
		},

		editPost() {
			// never the author's bio: an image-only post has no content, and
			// seeding the editor from account.note offered to publish it
			this.editContent = htmlToPlainText(this.item.content || '')
			this.editSpoiler = this.item.spoiler_text || ''
			this.isEditing = true
			this.$nextTick(() => {
				if (this.$refs.editInput) {
					this.$refs.editInput.focus()
				}
			})
		},

		async saveEdit() {
			if (!this.editCanSave) {
				return
			}

			const warning = this.editSpoiler.trim()
			const response = await this.timelineStore.postEdit({
				status: this.item,
				content: this.editContent.trim(),
				// fixing a typo used to un-hide sensitive content for every
				// follower, because the warning was always sent back empty
				spoiler_text: warning,
				sensitive: warning !== '' || this.item.sensitive === true,
			})
			if (response === undefined) {
				// the store already said so; keep what was typed
				return
			}

			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		cancelEdit() {
			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		/**
		 * Opens the confirmation. Deleting federates and cannot be undone, so
		 * neither half of this happens on the first click of a menu item.
		 *
		 * @param {boolean} redraft whether the words come back in the composer
		 */
		askToDelete(redraft) {
			this.deleteToRedraft = redraft
			this.showDeleteDialog = true
		},

		/**
		 * Deletes the post, and — for a re-draft — hands its words, pictures
		 * and warning to the composer.
		 *
		 * The composer is filled *after* the delete rather than before: a
		 * delete the server refuses leaves the post where it is, and a
		 * composer already holding its words would invite somebody to post it
		 * twice.
		 */
		async remove() {
			this.showDeleteDialog = false
			const redraft = this.deleteToRedraft
			this.deleteToRedraft = false

			// the post as it stands, kept before the store forgets it
			const draft = redraft ? this.item : null

			await this.timelineStore.postDelete(this.item)

			if (draft !== null) {
				this.timelineStore.setComposerDisplayStatus(true)
				eventBus.emit('composer-redraft', draft)
			}
		},

		/**
		 * Asks the server to translate the post, or puts the original back.
		 *
		 * Asked once per card: the answer is kept here, so pressing "Show
		 * original" and then "Translate" again costs nothing and does not send
		 * the same text through a provider twice.
		 */
		async toggleTranslation() {
			if (this.translation !== null) {
				this.translation = null

				return
			}

			if (this.translating) {
				return
			}

			this.translating = true
			try {
				const { data } = await axios.post(generateUrl('/apps/social/api/v1/statuses/{id}/translate', { id: this.item.id }))
				this.translation = data
			} catch (error) {
				// 503 is the server saying it has no translation provider,
				// which is a different thing from the request failing
				if (error?.response?.status === 503) {
					showError(t('social', 'This server cannot translate posts yet'))
				} else {
					showError(t('social', 'Could not translate this post'))
					logger.error('Failed to translate a post', { error })
				}
			} finally {
				this.translating = false
			}
		},

		/**
		 * Opens the delivery dialog and asks the server. Asked fresh every
		 * time: a delivery that was failing a minute ago may have gone through.
		 */
		async openDelivery() {
			this.showDeliveryDialog = true
			this.deliveryLoading = true
			this.deliveryError = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/statuses/{nid}/delivery', { nid: this.item.id }))
				this.delivery = data
			} catch {
				this.delivery = null
				this.deliveryError = t('social', 'Could not read the delivery status of this post.')
			} finally {
				this.deliveryLoading = false
			}
		},

		/**
		 * @param {{state: string, tries: number, last: number}} entry one server's row
		 * @return {string} its state, with the detail the state calls for
		 */
		deliveryStateLabel(entry) {
			switch (entry.state) {
				case 'delivered':
					return t('social', 'Delivered')
				case 'sending':
					return t('social', 'Sending')
				case 'waiting':
					return t('social', 'Waiting')
				case 'failing':
					return n('social', 'Failing (%n attempt)', 'Failing (%n attempts)', entry.tries)
				case 'abandoned':
					return n('social', 'Given up after %n attempt', 'Given up after %n attempts', entry.tries)
				default:
					return entry.state
			}
		},

		/**
		 * A vote is cast on the component's own copy of the poll; the store
		 * holds the one every other view reads, so it hears about it too.
		 *
		 * @param {object} poll the poll as the server returned it after voting
		 */
		updatePoll(poll) {
			this.localPoll = poll
			this.timelineStore.updateStatusPoll({ statusId: this.item.id, poll })
		},

		toggleBookmark() {
			this.timelineStore.postBookmark({ status: this.item, bookmarked: !this.item.bookmarked })
		},

		togglePin() {
			this.timelineStore.postPin({ status: this.item, pinned: !this.item.pinned })
		},

		async like() {
			const undo = this.isLiked
			await this.act('like', undo ? 'postUnlike' : 'postLike', !undo)
		},

		/**
		 * Both actions are applied optimistically and rolled back by the store
		 * when the server refuses — which used to happen invisibly. Confirming
		 * one animation and refusing the other makes the difference legible.
		 *
		 * @param {string} name 'like' or 'boost', the class hook
		 * @param {string} action the store action to dispatch
		 * @param {boolean} celebrating whether this is the doing, not the undoing
		 */
		async act(name, action, celebrating) {
			if (celebrating) {
				this.celebrate = name
				// a touch device can feel the confirmation as well as see it
				if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
					window.navigator.vibrate?.(8)
				}
				window.setTimeout(() => {
					if (this.celebrate === name) {
						this.celebrate = ''
					}
				}, 600)
			}

			const response = await this.timelineStore[action]({ status: this.item })
			if (response === undefined) {
				this.celebrate = ''
				this.refused = name
				window.setTimeout(() => {
					if (this.refused === name) {
						this.refused = ''
					}
				}, 400)
			}
		},
	},
}

</script>

<style scoped lang="scss">
/* the like confirmation: a short overshoot, not a bounce */
@keyframes post-pop {
	0% { transform: scale(1); }
	40% { transform: scale(1.35); }
	70% { transform: scale(.92); }
	100% { transform: scale(1); }
}

@keyframes post-burst {
	0% { transform: scale(.2); opacity: .55; }
	100% { transform: scale(2.4); opacity: 0; }
}

@keyframes post-spin {
	0% { transform: rotate(0); }
	100% { transform: rotate(360deg); }
}

/* the server refused: the optimistic change is being taken back */
@keyframes post-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

@media (max-width: 600px) {
	.post-content {
		// the screen's width is the post's: less of it goes to the frame
		padding: 14px 16px 12px;
	}
}

.post-content--openable {
	cursor: pointer;
}

.post-content {
	/* the bottom padding is what the pill sits in: it is 20px so that the
	   whole of the pill is either in this padding or in the 14px gap below the
	   card, and none of it is over anything anybody is reading */
	padding: 18px 20px 20px;
	font-size: 15px;
	line-height: 1.65;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	position: relative;
	z-index: 1;
	box-shadow: var(--social-elevation-resting);
	transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;

	&:hover {
		border-color: var(--color-primary-element);
		box-shadow: var(--social-elevation-raised);
		transform: translateY(-1px);
	}

	&:focus-within {
		border-color: var(--color-primary-element);
		box-shadow: 0 0 0 2px var(--color-primary-element-light);
	}

	/* The card keeps its own shape now. What used to happen here — the bottom
	   corners squaring off and the bottom border going transparent — was the
	   seam between the card and a panel as wide as it was. The pill is not that
	   wide and is not part of the card's outline, so there is no seam to hide.
	   The stacking order still has to change: the pill hangs into the gap and
	   the card below it comes later in the document. */
	&:hover,
	&:focus-within,
	&:has(.post-actions-reveal--held) {
		z-index: 4;
	}

	.post-header {
		display: flex;
		gap: 8px;
		align-items: baseline;
		margin-bottom: 10px;

		.post-author-wrapper {
			flex-grow: 1;
			min-width: 0;
			display: flex;
			align-items: baseline;

			.post-author {
				font-weight: 650;
				font-size: 14px;
				color: var(--color-main-text);
				letter-spacing: -.01em;
			}

			.post-author-id {
				font-size: 13px;
				color: var(--color-text-lighter);
				margin-inline-start: 6px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
		}

		// The row is aligned on the text baseline, which is right for the name
		// and the handle and wrong for anything that is an icon: an icon has no
		// text baseline of its own, so flexbox hangs it from its bottom edge and
		// it sits below the line it belongs on. These two carry icons, so they
		// are centred on the line instead.
		.post-place {
			display: inline-flex;
			gap: 3px;
			align-items: center;
			max-width: 40%;
			color: var(--color-text-maxcontrast);
			font-size: 12px;

			&:hover,
			&:focus-visible {
				text-decoration: underline;
			}
		}

		.post-tagged {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 4px;
			margin-block: 4px 0;
			color: var(--color-text-maxcontrast);
			font-size: 90%;
		}

		.post-tagged__person {
			color: var(--color-main-text);

			&:hover,
			&:focus-visible {
				text-decoration: underline;
			}
		}

		.post-place__name {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.post-visibility {
			color: var(--color-text-lighter);
			flex-shrink: 0;
			align-self: center;
		}

		.post-pinned {
			display: inline-flex;
			align-items: center;
			align-self: center;
			gap: 4px;
			flex-shrink: 0;
			font-size: 12px;
			// the same weight as the rest of the byline: the pin already says
			// this is a state, and a bold word beside grey text reads as the
			// loudest thing in a row that is all supporting detail
			font-weight: normal;
			color: var(--color-text-lighter);
		}

		.post-timestamp {
			// It opens the thread, so it stays a real button — reachable by
			// keyboard and announced as one. What it must not keep is the
			// chrome a bare <button> inherits from the server, which drew a
			// filled box around four characters of grey byline.
			background: none;
			border: none;
			border-radius: 0;
			padding: 0;
			margin: 0;
			min-height: 0;
			font-family: inherit;
			font-weight: normal;
			font-size: 12px;
			text-align: end;
			color: var(--color-text-lighter);
			white-space: nowrap;
			cursor: pointer;
			flex-shrink: 0;

			&:hover {
				color: var(--color-primary-element);
				background: none;
			}

			// the box was also the focus indicator; without one, a keyboard
			// reader loses the only affordance for opening a thread
			&:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 2px;
				border-radius: var(--border-radius-small, 4px);
			}
		}
	}

	.post-message {
		margin-bottom: 10px;
		overflow-wrap: break-word;
		overflow: visible;

		:deep(p) {
			margin: 0 0 8px;
			&:last-child {
				margin-bottom: 0;
			}
		}

		:deep(a) {
			overflow-wrap: anywhere;

			&:hover {
				text-decoration: underline;
			}
		}

		:deep(.mention) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(.hashtag) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(img) {
			max-width: 100%;
			height: auto;
			border-radius: 8px;
			margin: 12px 0;
			display: block;
		}
	}

	.post-edit-inline {
		margin-bottom: 10px;

		.post-edit-textarea {
			width: 100%;
			min-height: 100px;
			padding: 8px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-family: inherit;
			font-size: 15px;
			line-height: 1.65;
			resize: vertical;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-warning {
			width: 100%;
			margin-bottom: 6px;
			padding: 8px 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius, 8px);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 14px;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-actions {
			display: flex;
			gap: 8px;
			margin-top: 8px;
			align-items: center;
			justify-content: flex-end;
		}

		.post-edit-count {
			margin-inline-end: auto;
			font-size: 12px;
			color: var(--color-text-lighter);

			&--over {
				color: var(--color-error);
				font-weight: 600;
			}
		}
	}

	/*
	 * The action row is the loudest thing in a card and the least often used:
	 * somebody scrolling a timeline is reading, not boosting. So a card at rest
	 * carries nothing at all, and what arrives when the pointer does is one
	 * pill in the bottom-right corner: it fades in at the width of the overflow
	 * menu and widens to the left to let the rest of the row out.
	 *
	 * Only the pill changes size. The card does not grow, nothing below it
	 * moves, and — unlike the full-width panel this replaces — the pill is
	 * short enough to live in the card's own bottom padding and the fourteen
	 * pixels of gap below it, so it covers no part of the next card.
	 *
	 * A grid whose single column goes from `0fr` to `1fr` is what animates the
	 * width, for the same reason the height of a thing like this cannot be
	 * animated any other way: the row's width is not knowable in advance. A
	 * reply count going from 9 to 10 is another pixel, the icons are a
	 * translation away from being wider, and `width: auto` does not interpolate
	 * anywhere this app can rely on yet.
	 *
	 * The menu is deliberately outside the rail: it is the part the pill is as
	 * wide as when it arrives, and keeping it out of the animating column is
	 * what stops it drifting sideways while the pill opens.
	 *
	 * Nothing here is discoverable without a pointer, which is a real cost and
	 * a deliberate one — a mark on every card in a timeline is a hundred marks
	 * on a screen. Touch does not pay it: see the `hover: none` block at the
	 * end of this file, where the row is not a pill at all.
	 */
	.post-actions-reveal {
		position: absolute;
		/* 20px of the pill sits in the card's own bottom padding and 12px
		   hangs into the 14px gap: it covers neither the text above it nor the
		   card below it */
		top: calc(100% - 20px);
		inset-inline-end: 14px;
		z-index: 2;
		display: flex;
		padding: 1px;
		background: var(--color-main-background);
		border: 1px solid var(--color-primary-element);
		border-radius: 999px;
		box-shadow: var(--social-elevation-raised);
		/* nothing at all on a card nobody is pointing at */
		opacity: 0;
		pointer-events: none;
		transition: opacity .14s ease;
	}

	/*
	 * `focus-within` is not decoration here: it is the whole of the keyboard
	 * path. Tabbing into a card has to open the same pill the pointer does, or
	 * the buttons are focusable and invisible.
	 */
	&:hover .post-actions-reveal,
	&:focus-within .post-actions-reveal,
	.post-actions-reveal--held {
		opacity: 1;
		pointer-events: auto;
	}

	&:hover .post-actions__rail,
	&:focus-within .post-actions__rail,
	.post-actions-reveal--held .post-actions__rail {
		grid-template-columns: 1fr;

		.post-action-group {
			opacity: 1;
		}

		/* each one a beat behind the last, so the row arrives as a movement
		   rather than as three things at once */
		.post-action-group:nth-child(1) { transition-delay: .1s; }
		.post-action-group:nth-child(2) { transition-delay: .14s; }
		.post-action-group:nth-child(3) { transition-delay: .18s; }
	}

	.post-actions__rail {
		display: grid;
		grid-template-columns: 0fr;
		transition: grid-template-columns .34s cubic-bezier(.22, 1.1, .4, 1);
	}

	.post-actions__groups {
		display: flex;
		align-items: center;
		gap: 2px;
		/* the two halves of the column trick: the track may be zero wide, and
		   what is in it must be willing to be clipped rather than set a floor
		   under the track */
		min-inline-size: 0;
		overflow: hidden;

		.post-action-group {
			opacity: 0;
			transition: opacity .18s ease;
		}
	}

	.post-actions {
		display: flex;
		align-items: center;
		gap: 2px;

		.post-action-group {
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}

		.post-action-count {
			font-size: 12px;
			color: var(--color-text-lighter);
			min-width: 16px;
			text-align: center;
		}

		:deep(.button-vue) {
			border-radius: 8px;

			&:hover {
				background: var(--color-background-dark);
			}
		}

		/* 28px rather than the 34px a button is elsewhere: this is a
		   secondary row, and the pill has to be short enough to fit between
		   the text and the next card. Still above the 24px floor a pointer
		   target has. */
		:deep(.button-vue--icon-only) {
			min-height: 28px;
			min-width: 28px;
		}

		:deep(.button-vue) {
			border-radius: 999px;
		}
	}
}

.post-action-group {
	position: relative;

	&--refused :deep(button) {
		animation: post-refused .4s ease;
	}
}

.post-action--popped :deep(.material-design-icon) {
	animation: post-pop .45s cubic-bezier(.34, 1.56, .64, 1);
}

.post-action--spun :deep(.material-design-icon) {
	animation: post-spin .5s cubic-bezier(.4, 0, .2, 1);
}

/* the ring that expands out of the heart once */
.post-action__burst {
	position: absolute;
	top: 50%;
	inset-inline-start: 22px;
	width: 20px;
	height: 20px;
	margin: -10px 0 0 -10px;
	border-radius: 50%;
	background: var(--color-element-error);
	pointer-events: none;
	animation: post-burst .5s ease-out forwards;
}

.post-pinned {
	animation: none;
}

@media (prefers-reduced-motion: reduce) {
	.post-content,
	.post-content:hover {
		transition: none;
		transform: none;
	}

	.post-action-group--refused :deep(button),
	.post-action--popped :deep(.material-design-icon),
	.post-action--spun :deep(.material-design-icon) {
		animation: none;
	}

	.post-action__burst {
		display: none;
	}
}

.post-instance {
	flex-shrink: 0;
	margin-inline-start: 6px;
	padding: 1px 7px;
	border-radius: var(--border-radius-pill, 10px);
	font-size: 11px;
	font-weight: 600;
	letter-spacing: .01em;
	color: var(--color-primary-element-text);
	background: var(--instance-colour);
	max-width: 12ch;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
/**
 * A content warning is the author asking for their post not to be shown
 * until the reader chooses to see it. Honouring that is the whole point,
 * so the body is not in the DOM until it is opened.
 */
.post-warning {
	margin: 4px 0 8px;

	&__text {
		margin-bottom: 8px;
		font-weight: 600;
	}
}

/* the text of a picture post is its caption: smaller, and nearer the picture */
.post-message--caption {
	margin-top: 2px;
	font-size: 14.5px;
	color: var(--color-main-text);
}

.post-sensitive--leading {
	min-height: 180px;
}

.post-message--behind-warning {
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}

/**
 * A post one of the reader's own keyword filters matched. Boxed rather than
 * written as plain text like a content warning: this cover is the reader's own
 * doing and not the author's, and the two should not be mistaken for each
 * other. Nothing of the post is behind it — the body is not rendered at all
 * until the button is pressed.
 */
.post-filtered {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin: 10px 0;
	padding: 12px 16px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-background-dark);

	&__reason {
		margin: 0;
		font-weight: 600;
		min-width: 0;
		overflow-wrap: anywhere;
	}
}

/**
 * A post flagged sensitive without a warning shows its text but not its
 * pictures until the reader asks for them.
 */
.post-sensitive {
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 10px 0;
	padding: 20px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-background-dark);
}

.delete-hint {
	padding: 0 12px 12px;
	color: var(--color-text-lighter);
	line-height: 1.5;
}

.delivery-hint {
	padding: 0 12px 12px;
	color: var(--color-main-text);
	line-height: 1.5;

	&--muted {
		color: var(--color-text-lighter);
	}

	&--error {
		color: var(--color-error-text);
	}
}

/*
 * One row per server, the state carried by a dot as well as the word so the
 * list reads at a glance: green got there, amber is still trying, red was
 * given up on.
 */
.delivery-list {
	margin: 0 12px 12px;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 28px;
		font-size: 14px;
	}

	&__dot {
		flex: none;
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--color-text-maxcontrast);
	}

	&__host {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-variant-numeric: tabular-nums;
	}

	&__state {
		flex: none;
		color: var(--color-text-lighter);
		font-size: 13px;
	}

	&__row--delivered &__dot {
		background: var(--color-success);
	}

	&__row--sending &__dot,
	&__row--waiting &__dot {
		background: var(--color-warning);
	}

	&__row--failing &__dot {
		background: var(--color-warning);
		box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-warning) 30%, transparent);
	}

	&__row--abandoned &__dot {
		background: var(--color-error);
	}
}

/*
 * A finger cannot hover, so on a touch screen there is no state in which the
 * pill would ever open. It is not a pill there: the row goes back into the
 * card's flow, open, where it takes its own space and pushes nothing, because
 * nothing is moving.
 */
@media (hover: none) {
	.post-content .post-actions-reveal {
		position: static;
		padding: 0;
		background: none;
		border: none;
		border-radius: 0;
		box-shadow: none;
		/* the base state hides the pill until a pointer arrives, and none ever
		   does here */
		opacity: 1;
		pointer-events: auto;
	}

	.post-content .post-actions {
		margin-top: 10px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	.post-content .post-actions__rail {
		grid-template-columns: 1fr;
	}

	.post-content .post-action-group {
		opacity: 1;
	}

	/* the menu goes back to the far end of a full-width row */
	.post-content .post-actions :deep(.actions) {
		margin-inline-start: auto;
	}
}

/*
 * Reduced motion takes the movement away, not the reveal: the row still has to
 * arrive when the pointer does, it just stops widening to get there.
 */
@media (prefers-reduced-motion: reduce) {
	.post-content .post-actions__rail {
		transition-duration: .01ms;
	}

	.post-content .post-action-group {
		transition: none;
		transition-delay: 0ms !important;
	}
}
</style>

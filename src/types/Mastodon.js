/**
 * @copyright Copyright (c) 2023 Louis Chmn <louis@chmn.me>
 *
 * @author Louis Chmn <louis@chmn.me>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * @typedef Field
 * @property {string} name - Ex: "Patreon"
 * @property {string} value - Ex: "<a href=\"https://www.patreon.com/mastodon\" rel=\"me nofollow noopener noreferrer\" target=\"_blank\"><span class=\"invisible\">https://www.</span><span class=\"\">patreon.com/mastodon</span><span class=\"invisible\"></span}"
 * @property {string} [verified_at] - Ex: "2019-12-08T03:48:33.901Z"
 */

/**
 * @typedef Card
 * @property {string} url - Ex: "https://www.theguardian.com/money/2019/dec/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code"
 * @property {string} title - Ex: "‘I lost my £193,000 inheritance – with one wrong digit on my sort code’"
 * @property {string} description - Ex: "When Peter Teich’s money went to another Barclays customer, the bank offered £25 as a token gesture"
 * @property {string} type - Ex: "link"
 * @property {string} author_name -
 * @property {string} author_url -
 * @property {string} provider_name -
 * @property {string} provider_url -
 * @property {string} html -
 * @property {number} width - Ex: 0
 * @property {number} height - Ex: 0
 * @property {number} [image] -
 * @property {string} embed_url -
 */

/**
 * @typedef Poll - https://docs.joinmastodon.org/entities/Poll/
 * @property {string} id - Ex: "34830"
 * @property {string} expires_at - Ex: "2019-12-05T04:05:08.302Z"
 * @property {boolean} expired - Ex: true
 * @property {boolean} multiple - Ex: false
 * @property {number} votes_count - Ex: 10
 * @property {number} [voters_count] - null
 * @property {boolean} voted - Ex: true
 * @property {number[]} own_votes - Ex: [1]
 * @property {PollOption[]} options - Ex: []
 * @property {CustomEmoji[]} emojis - []
 */

/**
 * @typedef PollOption
 * @property {string} title - Ex: "accept"
 * @property {number} votes_count - 6
 */

/**
 * @typedef StatusMention - https://docs.joinmastodon.org/entities/Status/#Mention
 * @property {string} id -
 * @property {string} username -
 * @property {string} url -
 * @property {string} acct -
 */

/**
 * @typedef StatusTag - https://docs.joinmastodon.org/entities/Status/#Tag
 * @property {string} name -
 * @property {string} url -
 */

/**
 * @typedef MediaAttachment - https://docs.joinmastodon.org/entities/MediaAttachment
   @property {string} id - Ex: "22345792"
   @property {string} type - Ex: "image"
   @property {string} url - Ex: "22345792"
   @property {string} preview_url - Ex: "https://files.mastodon.social/media_attachments/files/022/345/792/small/57859aede991da25.jpeg"
   @property {string} [remote_url] -
   @property {object} meta -
   @property {string} description - Ex: "test media description"
   @property {string} blurhash - Ex: "UFBWY:8_0Jxv4mx]t8t64.%M-:IUWGWAt6M}"
 */

/**
 * @typedef CustomEmoji
 * @property {string} shortcode - Ex: "blobaww"
 * @property {string} url - Ex: "https://files.mastodon.social/custom_emojis/images/000/011/739/original/blobaww.png"
 * @property {string} static_url - Ex: "static_url": "https://files.mastodon.social/custom_emojis/images/000/011/739/static/blobaww.png"
 * @property {boolean} visible_in_picker - Ex: "true"
 * @property {string} category - Ex: "Blobs"
 */

/**
 * @typedef Account - https://docs.joinmastodon.org/entities/Account
   @property {string} id - Ex: "22345792"
 * @property {string} username - Ex: "Gargron"
 * @property {string} acct - Ex: "Gargron@example.com or Gargron for local users"
 * @property {string} display_name - Ex: "Eugen"
 * @property {boolean} locked - Ex: false
 * @property {boolean} bot - Ex: false
 * @property {number} discoverable - Ex: true
 * @property {boolean} group - Ex: false
 * @property {string} created_at - Ex: "2016-03-16T14:34:26.392Z"
 * @property {string} note - Ex: "<p>Developer of Mastodon and administrator of mastodon.social. I post service announcements, development updates, and personal stuff.</p>"
 * @property {string} url - Ex: "https://mastodon.social/@Gargron"
 * @property {string} avatar - Ex: "https://files.mastodon.social/accounts/avatars/000/000/001/original/d96d39a0abb45b92.jpg"
 * @property {string} avatar_static - Ex: "https://files.mastodon.social/accounts/avatars/000/000/001/original/d96d39a0abb45b92.jpg"
 * @property {string} header - Ex: "https://files.mastodon.social/accounts/headers/000/000/001/original/c91b871f294ea63e.png"
 * @property {string} header_static - Ex: "https://files.mastodon.social/accounts/headers/000/000/001/original/c91b871f294ea63e.png"
 * @property {number} followers_count - Ex: 322930
 * @property {number} following_count - Ex: 459
 * @property {number} statuses_count - Ex: 61323
 * @property {string} last_status_at - Ex: "2019-12-10T08:14:44.811Z"
 * @property {CustomEmoji[]} emojis - Ex: []
 * @property {Field[]} fields - Ex: []
 */

/**
 * @typedef Status - https://docs.joinmastodon.org/entities/Status
 * @property {string} id - Ex: "103270115826048975"
 * @property {string} created_at - Ex: "2019-12-08T03:48:33.901Z"
 * @property {string} [in_reply_to_id] - Ex: Ex: "103270115826048975"
 * @property {number} [in_reply_to_account_id] - Ex: "1"
 * @property {boolean} sensitive - Ex: false
 * @property {string} spoiler_text -
 * @property {string} visibility - Ex: "public"
 * @property {string} language - Ex: "en"
 * @property {string} uri - Ex: "https://mastodon.social/users/Gargron/statuses/103270115826048975"
 * @property {string} url - Ex: "https://mastodon.social/@Gargron/103270115826048975"
 * @property {number} replies_count - Ex: 5
 * @property {number} reblogs_count - Ex: 6
 * @property {number} favourites_count - Ex: 11
 * @property {boolean} [favourited] - Ex: false
 * @property {boolean} [reblogged] - Ex: false
 * @property {boolean} [muted] - Ex: false
 * @property {boolean} [bookmarked] - Ex: false
 * @property {string} content - Ex: "<p>&quot;I lost my inheritance with one wrong digit on my sort code&quot;</p><p><a href=\"https://www.theguardian.com/money/2019/dec/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"><span class=\"invisible\">https://www.</span><span class=\"ellipsis\">theguardian.com/money/2019/dec</span><span class=\"invisible\">/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code</span}</p>"
 * @property {Status?} reblog - Ex: null
 * @property {object} [application] -
 * @property {string} application.name - Ex: "Web"
 * @property {string} [application.website] - Ex: null
 * @property {Account} account -
 * @property {MediaAttachment[]} media_attachments - Ex: []
 * @property {StatusMention[]} mentions - Ex: []
 * @property {StatusTag[]} tags - Ex: []
 * @property {CustomEmoji[]} emojis - Ex: []
 * @property {Card} card -
 * @property {Poll} [poll] - Ex: null
 */

/**
 * @typedef Context - https://docs.joinmastodon.org/entities/Context
 * @property {Status[]} ancestors - Parents in the thread.
 * @property {Status[]} descendants - Children in the thread.
 */

/**
 * @typedef Notification - https://docs.joinmastodon.org/entities/Notification
 * @property {string} id - Ex: "https://example.com/users/@tommy""
 * @property {"mention"|"status"|"reblog"|"follow"|"follow_request"|"favourite"|"poll"|"update"|"admin.sign_up"|"admin.report"} type - Ex: "2016-03-16T14:34:26.392Z"
 * @property {string} created_at - Ex: "2016-03-16T14:34:26.392Z"
 * @property {Account} account -
 * @property {Status} [status] -
 * @property {any} [report] -
 */

/**
 * @typedef Relationship - https://docs.joinmastodon.org/entities/Relationship
 * @property {string} id - The account ID. Ex: "https://example.com/users/@tommy""
 * @property {boolean} following - Are you following this user?
 * @property {boolean} showing_reblogs - Are you receiving this user’s boosts in your home timeline?
 * @property {boolean} notifying - Have you enabled notifications for this user?
 * @property {string[]} languages - Which languages are you following from this user?
 * @property {boolean} followed_by - Are you followed by this user?
 * @property {boolean} blocking - Are you blocking this user?
 * @property {boolean} blocked_by - Is this user blocking you?
 * @property {boolean} muting - Are you muting this user?
 * @property {boolean} muting_notifications - Are you muting notifications from this user?
 * @property {boolean} requested - Do you have a pending follow request for this user?
 * @property {boolean} domain_blocking - Are you blocking this user’s domain?
 * @property {boolean} endorsed - Are you featuring this user on your profile?
 * @property {string} note - This user’s profile bio
 */

export default {}

/*
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
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

import axios from '@nextcloud/axios'

class FollowException {

}

class UnfollowException {

}

export default {
	data() {
		return {
			followLoading: false
		}
	},
	methods: {
		follow() {
			this.followLoading = true
			return axios.put(OC.generateUrl('/apps/social/api/v1/current/follow?account=' + this.item.account)).then((response) => {
				this.followLoading = false
				if (response.data.status === -1) {
					throw new FollowException()
				}
				this.item.details.following = true
			}).catch((error) => {
				this.followLoading = false
				OC.Notification.showTemporary(`Failed to follow user ${this.item.account}`)
				console.error(`Failed to follow user ${this.item.account}`, error.response.data)
			})

		},
		unfollow() {
			this.followLoading = true
			return axios.delete(OC.generateUrl('/apps/social/api/v1/current/follow?account=' + this.item.account)).then((response) => {
				this.followLoading = false
				if (response.data.status === -1) {
					throw new UnfollowException()
				}
				this.item.details.following = false
			}).catch((error) => {
				this.followLoading = false
				OC.Notification.showTemporary(`Failed to unfollow user ${this.item.account}`)
				console.error(`Failed to unfollow user ${this.item.account}`, error.response.data)
			})
		}
	}
}

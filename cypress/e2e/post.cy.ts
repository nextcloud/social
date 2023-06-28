/**
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

import { User } from "@nextcloud/cypress"
import { randHash } from "../utils"

const alice = new User(`alice_${randHash()}`)

describe('Create posts', () => {
	before(() => {
		cy.createUser(alice)
		cy.login(alice)
		cy.visit('/apps/social')
		cy.createRandomUser()
			.then((user) => {
				cy.login(user)
				cy.visit('/apps/social')
			})
	})

	it('See the empty content illustration', () => {
		cy.get('.social__welcome').find('.icon-close').click()
		cy.get('.app-social .empty-content').should('be.visible').contains('No posts found')
		cy.reload()
	})

	it('Write a post to followers', () => {
		cy.intercept({ times: 1, method: 'POST', url: '/index.php/apps/social/api/v1/statuses' }).as('postMessage')
		cy.get('.new-post button[type=submit]').should('be.disabled')
		cy.get('.new-post').find('[contenteditable]').type('Hello world')
		cy.get('.new-post button[type=submit]').should('not.be.disabled')
		cy.get('.new-post button[type=submit]').click()
		cy.wait('@postMessage')
		cy.get('.social__timeline .timeline-entry:first-child').should('contain', 'Hello world')
	})

	it('No longer see the empty content illustration', () => {
		cy.get('.app-social .empty-content').should('not.exist')
	})

	it('Write a post to followers with ctrl+enter', () => {
		cy.intercept({ times: 1, method: 'POST', url: '/index.php/apps/social/api/v1/statuses' }).as('postMessage')
		cy.get('.new-post').find('[contenteditable]').type('Hello world 2{ctrl}{enter}')
		cy.wait('@postMessage')
		cy.get('.social__timeline .timeline-entry:first-child').should('contain', 'Hello world 2')
	})

	it('Write a post to @alice', () => {
		cy.intercept({ times: 1, method: 'POST', url: '/index.php/apps/social/api/v1/statuses' }).as('postMessage')
		cy.intercept({ times: 1, method: 'GET', url: '/index.php/apps/social/api/v1/global/accounts/search' })
		cy.get('.new-post').find('[contenteditable]').type(`@${alice.userId}`)
		cy.get('.tribute-container').should('be.visible')
		cy.get('.tribute-container ul li:first').contains(alice.userId)
		cy.get('.new-post').find('[contenteditable]').type('{enter} Hello there')
		cy.get('.new-post button[type=submit]').click()
		cy.wait('@postMessage')
		cy.get('.social__timeline .timeline-entry:first-child').should('contain', `@${alice.userId}`)
	})

	it('Opens the menu and shows that followers is selected by default', () => {
		cy.intercept({ times: 1, method: 'POST', url: '/index.php/apps/social/api/v1/statuses' }).as('postMessage')
		cy.intercept({ times: 1, method: 'GET', url: '/index.php/apps/social/api/v1/global/accounts/search' })
		cy.get('.new-post').find('[contenteditable]').type(`@${alice.userId}{enter} Hello world`)
		cy.wait(500)
		cy.get('.new-post button[type=submit]').should('not.be.disabled')
		const visibilityButton = cy.get('.new-post .options > .action-item > div > button')
		visibilityButton.find('.material-design-icon').should('have.class', 'account-multiple-icon')

		visibilityButton.click()
		cy.get('.v-popper__popper ').should('be.visible')
		cy.get('.v-popper__popper .selected-visibility').contains('Visible to followers only')
		visibilityButton.click()
		cy.get('.v-popper__popper ').should('not.be.visible')

		cy.get('.new-post button[type=submit]').click()
		cy.wait('@postMessage')
		cy.get('.social__timeline .timeline-entry:first-child').should('contain', 'Hello world').should('contain', `@${alice.userId}`)

	})

})

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

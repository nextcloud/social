/// <reference types="cypress" />

const { afterEach, describe, beforeEach, context, it } = require("mocha");

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
describe('Social posting Init', () => {
	beforeEach(() => {
		cy.log('Global init completed.')
	})

	context('Social posting context', () => {
		beforeEach(() => {
			cy.log('Social posting context started.')
			let userId = 'janedoe' + Date.now();
			cy.login('admin', 'admin', '/apps/social/')
			cy.nextcloudCreateUser(userId, 'p4ssw0rd')
			cy.login(userId, 'p4ssw0rd')
			cy.get('.app-content').should('be.visible')
			cy.log('Social posting context success.')
		})

		afterEach(() => {
			cy.logout()
		})

		describe('Create posts', () => {	
			afterEach(() => {
				cy.screenshot()
			})
		
			beforeEach(() => {
				cy.log('Social posting test-setup started.')
				cy.clearCookies()
				cy.log('Social posting test-setup success.')
			})
		
			it('See the empty content illustration', () => {
				cy.get('.emptycontent').should('be.visible').contains('No posts found')
			})
		
			it('Write a post to followers', () => {
				cy.visit('/apps/social/')
				cy.intercept('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
				cy.get('.new-post input[type=submit]')
					.should('be.disabled')
				cy.get('.new-post').find('[contenteditable]').type('Hello world')
				cy.get('.new-post input[type=submit]')
					.should('not.be.disabled')
				cy.get('.new-post input[type=submit]')
					.click()
				cy.wait('@postMessage')
				cy.get('.social__timeline div.timeline-entry:first-child').should('contain', 'Hello world')
			})
		
			it('No longer see the empty content illustration', () => {
				cy.get('.emptycontent').should('not.be.visible')
			})
		
			it('Write a post to followers with shift enter', () => {
				cy.visit('/apps/social/')
				cy.intercept('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
				cy.get('.new-post').find('[contenteditable]').type('Hello world 2{shift}{enter}')
				cy.wait('@postMessage')
				cy.get('.social__timeline div.timeline-entry:first-child').should('contain', 'Hello world 2')
			})
		
			it('Write a post to @admin', () => {
				cy.visit('/apps/social/')
				cy.intercept('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
				cy.intercept('GET', '/index.php/apps/social/api/v1/global/accounts/search')
				cy.get('.new-post').find('[contenteditable]').type('@adm', {delay: 500})
				cy.get('.tribute-container').should('be.visible')
				cy.get('.tribute-container ul li:first').contains('admin')
				cy.get('.new-post').find('[contenteditable]').type('{enter} Hello there', {delay: 100, force: true})
				cy.get('.new-post input[type=submit]')
					.click()
				cy.wait('@postMessage')
				cy.get('.social__timeline div.timeline-entry:first-child').should('contain', '@admin')
			})
		
			it('Opens the menu and shows that followers is selected by default', () => {
				cy.visit('/apps/social/')
				cy.intercept('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
				cy.intercept('GET', '/index.php/apps/social/api/v1/global/accounts/search')
				cy.get('.new-post').find('[contenteditable]').click({force: true}).type('@adm{enter} Hello world', {delay: 500, force: true})
				cy.wait(500)
				cy.get('.new-post input[type=submit]').should('not.be.disabled')
				const visibilityButton = cy.get('.new-post .options > div > button')
				visibilityButton.should('have.class', 'icon-contacts-dark')
		
				visibilityButton.click()
				cy.get('.new-post-form .popovermenu').should('be.visible')
				cy.get('.new-post-form .popovermenu .active').contains('Followers')
				visibilityButton.click()
				cy.get('.new-post-form .popovermenu').should('not.be.visible')
		
				cy.get('.new-post input[type=submit]')
					.click()
				cy.wait('@postMessage')
				cy.get('.social__timeline div.timeline-entry:first-child').should('contain', 'Hello world').should('contain', '@admin')
		
			})
		
		})
		
	})
})
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

describe('Create posts', function() {

	before(function() {
		cy.visit('/apps/social/')
		cy.logout()
		cy.login('admin', 'admin')
		cy.nextcloudCreateUser('janedoe', 'p4ssw0rd')
		cy.logout()
		cy.login('janedoe', 'p4ssw0rd', '/apps/social/')
		cy.get('.app-content').should('be.visible')
	})

	afterEach(function() {
		cy.screenshot()
	})

	it('Write a post to followers', function() {
		cy.server()
		cy.route('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
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

	it('Write a post to followers with shift enter', function() {
		cy.server()
		cy.route('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
		cy.get('.new-post').find('[contenteditable]').type('Hello world 2{shift}{enter}')
		cy.wait('@postMessage')
		cy.get('.social__timeline div.timeline-entry:first-child').should('contain', 'Hello world 2')
	})

	it('Write a post to @admin', function() {
		cy.server()
		cy.route('POST', '/index.php/apps/social/api/v1/post').as('postMessage')
		cy.route('GET', '/index.php/apps/social/api/v1/global/accounts/search')
		cy.get('.new-post').find('[contenteditable]').type('@adm', {delay: 500})
		cy.get('.tribute-container').should('be.visible')
		cy.get('.tribute-container ul li:first').contains('admin')
		cy.get('.new-post').find('[contenteditable]').type('{enter} Hello there', {delay: 100, force: true})
		cy.get('.new-post input[type=submit]')
			.click()
		cy.wait('@postMessage')
		cy.get('.social__timeline div.timeline-entry:first-child').should('contain', '@admin')
	})

	it('Opens the menu and shows that followers is selected by default', function() {
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
		cy.get('.social__timeline div.timeline-entry:first-child').should('contain', 'Hello there').should('contain', '@admin')

	})

})

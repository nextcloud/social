/// <reference types="cypress" />

context('Social app init'), () => {
	let userId = 'janedoe' + Date.now();

	before(() => {
		cy.login('admin', 'admin', '/apps/social/')
		cy.nextcloudCreateUser(userId, 'p4ssw0rd')
		cy.login(userId, 'p4ssw0rd')
		cy.get('.app-content').should('be.visible')
	})

	describe('Social app setup', () => {
		beforeEach(() => {
			Cypress.Cookies.preserveOnce('nc_username', 'nc_token', 'nc_session_id', 'oc_sessionPassphrase');
		})
	
		it('See the welcome message', () => {
			cy.visit('/apps/social/')
			cy.get('.social__welcome').should('contain', 'Nextcloud becomes part of the federated social networks!')
			cy.get('.social__welcome').find('.icon-close').click()
			cy.get('.social__welcome').should('not.exist')
		})
	
		it('See the home section in the sidebar', () => {
			cy.get('.app-navigation').contains('Home').click()
			cy.get('.emptycontent').should('be.visible')
		})
	
		it('See the empty content illustration', () => {
			cy.get('.app-navigation').contains('Direct messages').click()
			cy.get('.emptycontent').should('be.visible').contains('No direct messages found')
			cy.get('.app-navigation').contains('Profile').click()
			cy.get('.emptycontent').should('be.visible').contains('You haven\'t tooted yet')
		})
	
	})
	
}


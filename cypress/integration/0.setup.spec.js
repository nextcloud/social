let userId = 'janedoe' + Date.now();

describe('Social app setup', function() {
	before(function() {
		cy.nextcloudCreateUser(userId, 'p4ssw0rd')
		cy.login(userId, 'p4ssw0rd')
	})

	beforeEach(() => {
		Cypress.Cookies.preserveOnce('nc_username', 'nc_token', 'nc_session_id', 'oc_sessionPassphrase');
	})

	it('See the welcome message', function() {
		cy.visit('/apps/social/')
		cy.get('.social__welcome').should('contain', 'Nextcloud becomes part of the federated social networks!')
		cy.get('.social__welcome').find('.icon-close').click()
		cy.get('.social__welcome').should('not.exist')
	})

	it('See the home section in the sidebar', function() {
		cy.get('.app-navigation').contains('Home').click()
		cy.get('.emptycontent').should('be.visible')
	})

	it('See the empty content illustration', function() {
		cy.get('.app-navigation').contains('Direct messages').click()
		cy.get('.emptycontent').should('be.visible').contains('No direct messages found')
		cy.get('.app-navigation').contains('Profile').click()
		cy.get('.emptycontent').should('be.visible').contains('You haven\'t tooted yet')
	})

})

const userId = 'janedoe' + Date.now()

describe('Social app setup', function () {
	before(function () {
		cy.createRandomUser()
			.then((user) => {
				cy.login(user)
				cy.visit('/apps/social')
			})
	})

	it('See the welcome message', function () {
		cy.get('.social__welcome').should('contain', 'Nextcloud becomes part of the federated social networks!')
		cy.get('.social__welcome').find('.icon-close').click()
		cy.get('.social__welcome').should('not.exist')
	})

	it('See the home section in the sidebar', function () {
		cy.get('.app-navigation').contains('Home').click()
		cy.get('.app-social .empty-content').should('be.visible')
	})

	it('See the empty content illustration', function () {
		cy.reload()
		cy.get('.app-navigation').contains('Direct messages').click()
		cy.get('.app-social .empty-content').should('be.visible').contains('No direct messages found')
		cy.get('.app-navigation').contains('Profile').click()
		cy.get('.app-social .empty-content').should('be.visible').contains('You have not tooted yet')
	})
})

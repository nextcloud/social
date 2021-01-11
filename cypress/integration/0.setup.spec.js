/// <reference types="cypress" />

const { afterEach, describe, beforeEach, context, it } = require("mocha");

describe('Social app init', () => {
	beforeEach(() => {
		cy.log('Global init completed.')
	})

	context('Social app context', () => {

		beforeEach(() => {
			cy.log('Social app context init started.')
			let userId = 'janedoe' + Date.now();
			cy.login('admin', 'admin', '/apps/social/')
			cy.nextcloudCreateUser(userId, 'p4ssw0rd')
			cy.login(userId, 'p4ssw0rd')
			cy.get('.app-content').should('be.visible')
			cy.log('Social app context init success.')
		})

		afterEach(() => {
			cy.logout()
		})

		describe('Social app setup', () => {
			beforeEach(() => {
				cy.log('Social app setup started.')
				cy.clearCookies()
				cy.log('Social app test-setup success.')
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
		
	})
})
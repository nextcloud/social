// ***********************************************************
// This example support/index.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
import './commands'

// Alternatively you can use CommonJS syntax:
// require('./commands')
let userId = 'janedoe' + Date.now();

before(function() {
    cy.login('admin', 'admin', '/apps/social/')
    cy.nextcloudCreateUser(userId, 'p4ssw0rd')
    cy.login(userId, 'p4ssw0rd')
    cy.get('.app-content').should('be.visible')
})

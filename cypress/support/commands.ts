/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { basename } from 'path'

export class User {
	userId: string
	password: string

	constructor(userId: string, password?: string) {
		this.userId = userId
		this.password = password || 'password'
	}
}

const url = Cypress.config('baseUrl').replace(/\/index.php\/?$/g, '')
Cypress.env('baseUrl', url)

// Custom login using cy.session() (Cypress 12+ API, compatible with Cypress 15)
Cypress.Commands.add('login', (user: User | string, password?: string, route?: string) => {
	const username = typeof user === 'object' ? user.userId : user
	const pass = typeof user === 'object' ? user.password : (password ?? user as string)
	const targetRoute = route ?? '/apps/social'

	cy.session([username, pass, targetRoute], () => {
		cy.clearCookies()
		cy.visit(targetRoute)
		cy.get('input[name=user]').type(username)
		cy.get('input[name=password]').type(pass)
		cy.get('form[name=login] [type=submit]').click()
		cy.url().should('include', targetRoute)
	}, {
		validate() {
			cy.visit(targetRoute)
			cy.get('body').should('not.have.descendants', 'form[name=login]')
		},
	})
})

// Custom logout
Cypress.Commands.add('logout', () => {
	cy.document().then(document => {
		const tokenElement = document.getElementsByTagName('head')[0]
		const token = tokenElement.getAttribute('data-requesttoken') || ''
		cy.visit(`/logout?requesttoken=${encodeURIComponent(token)}`)
		cy.url().should('include', '/login')
	})
})

Cypress.Commands.add('createUser', (user: User) => {
	const baseUrl = Cypress.env('baseUrl')
	return cy.request({
		method: 'POST',
		url: `${baseUrl}/ocs/v2.php/cloud/users`,
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: `userid=${user.userId}&password=${user.password}`,
		auth: {
			username: 'admin',
			password: 'admin',
		},
	})
})

Cypress.Commands.add('createRandomUser', () => {
	const randomId = Math.random().toString(36).replace(/[^a-z]+/g, '').slice(0, 10)
	const user = new User(randomId)
	cy.createUser(user)
	return cy.wrap(user)
})

Cypress.Commands.add('uploadFile', (fileName, mimeType, path = '') => {
	// get fixture
	return cy.fixture(fileName, 'base64').then(file => {
		// convert the logo base64 string to a blob
		const blob = Cypress.Blob.base64StringToBlob(file, mimeType)
		try {
			const file = new File([blob], fileName, { type: mimeType })
			return cy.window().then(async window => {
				await axios.put(`${Cypress.env('baseUrl')}/remote.php/webdav${path}/${fileName}`, file, {
					headers: {
						requesttoken: window.OC.requestToken,
						'Content-Type': mimeType,
					},
				}).then(response => {
					cy.log(`Uploaded ${fileName}`, response)
				})
			})
		} catch (error) {
			cy.log(error)
			throw new Error(`Unable to process file ${fileName}`)
		}
	})

})

Cypress.Commands.add('createFolder', dirName => {
	cy.get('#controls .actions > .button.new').click()
	cy.get('#controls .actions .newFileMenu a[data-action="folder"]').click()
	cy.get('#controls .actions .newFileMenu a[data-action="folder"] input[type="text"]').type(dirName)
	cy.get('#controls .actions .newFileMenu a[data-action="folder"] input.icon-confirm').click()
	cy.log('Created folder', dirName)
})

Cypress.Commands.add('openFile', fileName => {
	cy.get(`#fileList tr[data-file="${fileName}"] a.name`).click()
	cy.wait(250)
})

Cypress.Commands.add('getFileId', fileName => {
	return cy.get(`#fileList tr[data-file="${fileName}"]`)
		.should('have.attr', 'data-id')
})

Cypress.Commands.add('deleteFile', fileName => {
	cy.get(`#fileList tr[data-file="${fileName}"] a.name .action-menu`).click()
	cy.get(`#fileList tr[data-file="${fileName}"] a.name + .popovermenu .action-delete`).click()
})

/**
 * Create a share link and return the share url
 *
 * @param {string} path the file/folder path
 * @return {string} the share link url
 */
Cypress.Commands.add('createLinkShare', path => {
	return cy.window().then(async window => {
		try {
			const request = await axios.post(`${Cypress.env('baseUrl')}/ocs/v2.php/apps/files_sharing/api/v1/shares`, {
				path,
				shareType: window.OC.Share.SHARE_TYPE_LINK,
			}, {
				headers: {
					requesttoken: window.OC.requestToken,
				},
			})
			if (!('ocs' in request.data) || !('token' in request.data.ocs.data && request.data.ocs.data.token.length > 0)) {
				throw request
			}
			cy.log('Share link created', request.data.ocs.data.token)
			return cy.wrap(request.data.ocs.data.token)
		} catch (error) {
			console.error(error)
		}
	}).should('have.length', 15)
})

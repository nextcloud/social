/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { addCommands } from '@nextcloud/cypress'
import { basename } from 'path'

export class User {
	userId: string
	password: string

	constructor(userId: string, password?: string) {
		this.userId = userId
		this.password = password || 'password'
	}
}

// Add custom commands
import 'cypress-wait-until'
addCommands()

const url = Cypress.config('baseUrl').replace(/\/index.php\/?$/g, '')
Cypress.env('baseUrl', url)

// Override login to accept User objects
Cypress.Commands.overwrite('login', (originalFn, user: User | string, password?: string, route?: string) => {
	if (typeof user === 'object' && user.userId) {
		return originalFn(user.userId, user.password, route ?? '/apps/files')
	}
	return originalFn(user, password ?? user as string, route ?? '/apps/files')
})

Cypress.Commands.add('createUser', (user: User) => {
	const baseUrl = Cypress.env('baseUrl')
	cy.request({
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

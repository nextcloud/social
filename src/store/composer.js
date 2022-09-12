// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

const state = {
	attachements: [],
	status: '',
	sensitive: false
}

const mutations = {
	addAttachement(state, { id, description, url, preview_url }) {
		state.attachements.push({ id, description, url, preview_url })
	},
	updateAttachement(state, { id, description, url, preview_url }) {
		const index = state.attachements.findIndex(item => {
			return id === item.id
		})
		state.attachements.splice(index, 1, { id, description, url, preview_url })
	},
	deleteAttachement(state, { id }) {
		const index = state.attachements.findIndex(item => {
			return id === item.id
		})
		state.attachements.splice(index, 1)
	},
	clearAttachements(state) {
		state.attachements.splice(0)
	},
	updateSensitive(sensitive, status) {
		state.sensitive = sensitive
	}
}

const actions = {
	async uploadAttachement(context, formData) {
		const res = await axios.post(generateUrl('apps/social/api/v1/media'), formData, {
			headers: {
				'Content-Type': 'multipart/form-data'
			}
		})
		context.commit('addAttachement', {
			id: res.data.id,
			description: res.data.description,
			url: res.data.url,
			preview_url: res.data.preview_url
		})
	},
	async updateAttachement(context, { id, description }) {
		const res = await axios.put(generateUrl('apps/social/api/v1/media/' + id), {
			description
		})
		context.commit('updateAttachement', {
			id: res.data.id,
			description: res.data.description,
			url: res.data.url,
			preview_url: res.data.preview_url
		})
	},
	async deleteAttachement(context, { id }) {
		const res = await axios.delete(generateUrl('apps/social/api/v1/media/' + id))
		context.commit('deleteAttachement', {
			id: res.data.id
		})
	},
	async postStatus({ commit, state }, text) {
		const data = {
			status: text,
			media_ids: state.attachements.map(attachement => attachement.id),
			sensitive: state.sensitive
		}
		try {
			const response = await axios.post(generateUrl('apps/social/api/v1/statuses'), data)
		} catch (error) {
			OC.Notification.showTemporary('Failed to create a post')
			Logger.error('Failed to create a post', { 'error': error.response })
		}
		commit('clearAttachements')
	}
}

// SPDX-FileCopyrightText: 2026 Aloha Social contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { PasswordSession } from '@atproto/lex-password-session'

const session = await PasswordSession.login({
	service: process.env.PDS_URL,
	identifier: process.env.PDS_IDENTIFIER,
	password: process.env.PDS_PASSWORD,
})

const call = async (path) => {
	const response = await session.fetchHandler(path, { method: 'GET' })
	const body = await response.json().catch(() => null)
	return { status: response.status, body }
}

const who = await call('/xrpc/com.atproto.server.getSession')
const refreshed = await session.refresh()
const actor = encodeURIComponent(process.env.PROFILE_DID)
const profile = await call(`/xrpc/app.bsky.actor.getProfile?actor=${actor}`)
const authorFeed = await call(`/xrpc/app.bsky.feed.getAuthorFeed?actor=${actor}&filter=posts_with_replies&limit=10`)
const timeline = await call('/xrpc/app.bsky.feed.getTimeline?limit=10')
const did = session.did
const tokens = session.session
await session.logout()
const accessAfterLogout = await fetch(new URL('/xrpc/com.atproto.server.getSession', process.env.PDS_URL), {
	headers: { authorization: `Bearer ${tokens.accessJwt}` },
})
const refreshAfterLogout = await fetch(new URL('/xrpc/com.atproto.server.refreshSession', process.env.PDS_URL), {
	method: 'POST',
	headers: { authorization: `Bearer ${tokens.refreshJwt}` },
})

console.log(JSON.stringify({
	did,
	who,
	refreshed: { did: refreshed.did, handle: refreshed.handle },
	profile,
	authorFeed,
	timeline,
	logout: { accessStatus: accessAfterLogout.status, refreshStatus: refreshAfterLogout.status },
}))

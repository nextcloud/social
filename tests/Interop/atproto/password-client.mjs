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
const timeline = await call('/xrpc/app.bsky.feed.getTimeline?limit=1')
await session.logout()

console.log(JSON.stringify({ did: session.did, who, timeline }))

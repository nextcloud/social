/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * What each code of an error says to the person, with what they can do next:
 * the codes of the Bluesky routes (Atproto-Compatibility §13.5: a move, the
 * handle, an app password) and of the Migration routes (API.md, Migration
 * error codes). The server's own sentence is English and names the step that
 * failed, so it is kept for the details.
 *
 * @type {Record<string, () => string>}
 */
const SENTENCES = {
	'bluesky-off': () => t('social', 'Bluesky is not switched on on this server. Ask your administrator about it.'),
	'no-identity': () => t('social', 'Your account has no Bluesky account on this server, so there is nothing to move.'),
	'identity-inactive': () => t('social', 'Your Bluesky account is switched off. Switch on "Be on Bluesky" first, then try again.'),
	'move-in-progress': () => t('social', 'Another move of your account is under way. Wait for it to finish, or call it off first.'),
	'pds-address-invalid': () => t('social', 'That server address does not look right. Check it and try again.'),
	'pds-not-public': () => t('social', 'That server cannot be reached from the internet. Check the address and try again.'),
	'pds-unreachable': () => t('social', 'The other server could not be reached. Check the address, or try again later.'),
	'pds-unknown': () => t('social', 'That address is not a server Bluesky accounts can live on. Check it and try again.'),
	'pds-refused': () => t('social', 'The other server refused a step of the move. Try again; if it happens again, the details say which step.'),
	'pds-session-expired': () => t('social', 'The sign-in on the other server ran out. Try again.'),
	'handle-not-allowed': () => t('social', 'That server does not give out handles like this one. Pick a handle that ends in one of its domains; the details name them.'),
	'handle-taken': () => t('social', 'That handle is taken on the other server. Pick another one.'),
	'invite-code-required': () => t('social', 'That server needs an invite code. Ask the people who run it for one and enter it.'),
	'invite-code-invalid': () => t('social', 'The other server did not accept the invite code. Check it and try again.'),
	'password-not-accepted': () => t('social', 'The other server did not accept that password. Pick a longer one.'),
	'rate-limited': () => t('social', 'There were too many tries for now. Wait a while and try again.'),
	'account-not-created': () => t('social', 'The other server did not make the account. The details say why.'),
	'keys-sealed': () => t('social', 'This server cannot open its keys right now. Ask your administrator about it.'),
	'directory-unreachable': () => t('social', 'The directory that keeps Bluesky identifiers did not answer. Try again later.'),
	'export-failed': () => t('social', 'The archive could not be made. Try again later.'),
	'tool-missing': () => t('social', 'The recovery tool is not available on this server. Ask your administrator about it.'),
	'plc-unreachable': () => t('social', 'The directory that keeps Bluesky identifiers did not answer. Try again later.'),
	'plc-refused': () => t('social', 'The directory that keeps Bluesky identifiers refused the change. Try again; if it happens again, the details say why.'),
	'recovery-key-missing': () => t('social', 'You have no recovery phrase. Make one first, or confirm that only the new server will control your account.'),
	'no-move-to-call-off': () => t('social', 'There is no move to call off. Reload the page to see where it stands.'),
	'move-switching': () => t('social', 'Your account is being pointed at the new server right now. Wait a moment: the move then goes on or stops.'),
	'move-past-switch': () => t('social', 'Your account already points at the new server, so the move can no longer be called off. Use "Try again" to finish it.'),
	'move-called-off': () => t('social', 'The move was called off.'),
	'blobs-missing': () => t('social', 'Some pictures or videos did not arrive. Copy them again, or go on without them.'),
	'repo-incomplete': () => t('social', 'Your posts did not all arrive on the other server. Copy them again.'),
	'no-decision-pending': () => t('social', 'The move is not waiting for a decision any more. Reload the page to see where it stands.'),
	'announce-invalid': () => t('social', 'This server does not know that way of saying where your account went. Reload the page and choose again.'),
	'profile-fields-full': () => t('social', 'Your profile had no free field for your new address. Add it to your profile yourself.'),
	'announce-failed': () => t('social', 'Your new address could not be posted. Post it yourself.'),
	'bluesky-move-first': () => t('social', 'Move your Bluesky account first, or choose to switch it off or keep it.'),
	'bluesky-choice-invalid': () => t('social', 'Choose what happens to your Bluesky account: move it, switch it off or keep it.'),
	'handle-invalid': () => t('social', 'That is not a Bluesky handle. Check it and try again.'),
	'handle-not-found': () => t('social', 'No Bluesky account goes by that handle. Check it and try again.'),
	'did-not-supported': () => t('social', 'This kind of Bluesky account cannot be moved or read from here.'),
	'did-not-found': () => t('social', 'The directory that keeps Bluesky identifiers does not know that account. Check the handle and try again.'),
	'import-in-progress': () => t('social', 'These follows are being taken over already. See how far it got on the list of imports.'),
	'no-failed-move': () => t('social', 'There is no stopped move to start again. Reload the page to see where it stands.'),
	'move-refused': () => t('social', 'The move could not be started again. The details say why.'),
	'account-here-already': () => t('social', 'That Bluesky account is on this server already. There is nothing to move.'),
	'pds-not-named': () => t('social', 'That account does not say which server it lives on, so it cannot be moved.'),
	'pds-blocked': () => t('social', 'This server does not talk to the server that account lives on. Ask your administrator about it.'),
	'wrong-password': () => t('social', 'The password was not accepted. Use the account\'s own password, not an app password.'),
	'auth-factor-required': () => t('social', 'Bluesky e-mailed you a sign-in code. Enter it and start again.'),
	'sign-in-code-invalid': () => t('social', 'That sign-in code is not right. Start again to get a new one.'),
	'sign-in-code-expired': () => t('social', 'That sign-in code is too old. Start again to get a new one.'),
	'sign-in-refused': () => t('social', 'The old server did not let you sign in. The details say why.'),
	'no-move-waiting': () => t('social', 'No move is waiting for a code. Reload the page to see where it stands.'),
	'code-invalid': () => t('social', 'That code is not right. Check it, or ask for a new one.'),
	'code-expired': () => t('social', 'That code is too old. Ask for a new one.'),
	'repo-unavailable': () => t('social', 'The old server did not hand over your posts. Try again later.'),
	'repo-too-large': () => t('social', 'Your account is larger than this server takes. Ask your administrator about it.'),
	'blob-too-large': () => t('social', 'A picture or video is larger than this server takes. Go on without it.'),
	'repo-invalid': () => t('social', 'What the old server handed over is not your account. Ask the people who run that server.'),
	'operation-mismatch': () => t('social', 'The old server confirmed something other than what was asked. Try again, and tell your administrator if it happens again.'),
	'nothing-to-republish': () => t('social', 'There are no posts waiting to be published again.'),
	'republish-queued': () => t('social', 'Your posts are being published again already.'),
	'move-replaced': () => t('social', 'This invitation was replaced by a newer one.'),
	'invite-expired': () => t('social', 'The invitation ran out of time. Invite the account again.'),
	'move-expired': () => t('social', 'The move ran out of time. Invite the account again.'),
	'bridgy-unreachable': () => t('social', 'Bridgy Fed could not be reached. Try again later.'),
	'move-interrupted': () => t('social', 'It was cut off before it finished. Try again: it goes on from where it stopped.'),
	'move-failed': () => t('social', 'Something went wrong on the way. Try again; if it happens again, the details say what.'),
	'server-error': () => t('social', 'Something went wrong on this server. Try again; if it happens again, ask your administrator.'),
	'handle-domain-invalid': () => t('social', 'That is not a domain name. Type a domain you own, such as example.com.'),
	'handle-reserved': () => t('social', 'Handles under this server\'s own address are given out by the server. Use a domain of your own.'),
	'handle-in-use': () => t('social', 'Another account here already uses that domain as its handle. Use another domain.'),
	'handle-not-verified': () => t('social', 'That domain does not point at your account yet. Add the record shown below, wait a few minutes for it to spread, and try again.'),
	'app-password-name-invalid': () => t('social', 'Give the app password a name of up to 64 characters.'),
	'app-password-name-taken': () => t('social', 'You have an app password with that name already. Pick another name.'),
	'app-password-limit': () => t('social', 'You have as many app passwords as you can have. Revoke one you no longer use first.'),
	'not-signed-in': () => t('social', 'You are not signed in any more. Reload the page, sign in and try again.'),
	'no-file': () => t('social', 'No file arrived. Pick the file again and try again.'),
	'file-too-large': () => t('social', 'That file is larger than this server takes. The details say how large it may be; ask your administrator about a larger one.'),
	'file-unreadable': () => t('social', 'That file could not be read. Pick it again and try again.'),
	'archive-unreadable': () => t('social', 'That file is not an archive this page can read. Pick the zip file the export made.'),
	'archive-not-social': () => t('social', 'That archive holds no account of this app. Pick the zip file the export made.'),
	'import-running': () => t('social', 'That is under way already. See how far it got on the list of imports.'),
	'import-not-found': () => t('social', 'That import cannot be taken off the list now. Reload the page to see where it stands.'),
	'list-unknown': () => t('social', 'There is no such list to download.'),
	'no-account': () => t('social', 'You have no account here yet. Make one first.'),
	'account-address-invalid': () => t('social', 'That is not an account address. Type it like @you@example.social.'),
	'account-not-found': () => t('social', 'No account answers to that address. Check it and try again.'),
	'account-not-verifiable': () => t('social', 'That server only answers servers it can check, and it could not check this one. Ask your administrator to make this server reachable from the internet.'),
	'alias-self': () => t('social', 'That is this account itself. Type the address of your other account.'),
	'confirm-handle': () => t('social', 'Type your own handle to confirm the move. The details show it.'),
	'move-cooldown': () => t('social', 'This account moved not long ago. The details say when it can move again.'),
	'move-onto-self': () => t('social', 'An account cannot move onto itself. Type the address of your new account.'),
	'alias-missing': () => t('social', 'Your new account does not name this one yet. Add this account as an alias on the new account first, then try again.'),
	'finish-not-possible': () => t('social', 'The last step cannot be done from here for that server. Finish the move there, with its own button.'),
	'old-server-refused': () => t('social', 'The old server did not let this one finish the move. Try again later, or finish the move there.'),
	'video-address-invalid': () => t('social', 'That is not a video address. Copy the address of the video page and try again.'),
	'video-here-already': () => t('social', 'That video is on this server already. There is nothing to bring over.'),
	'video-unreachable': () => t('social', 'That address could not be read. Check it, or try again later.'),
	'video-unsupported': () => t('social', 'That address is not a video that can be brought over.'),
}

/**
 * @param {string|null|undefined} code a code the server sends beside its sentence
 * @return {string|null} what it means to the person, or null for a code this page does not know
 */
export function errorSentence(code) {
	return Object.hasOwn(SENTENCES, code ?? '') ? SENTENCES[code]() : null
}

/**
 * Why a request was refused, from the error axios threw.
 *
 * A route that sends no code, or one this page does not know, gets the
 * fallback: a sentence about what the person was doing, in their language.
 * What the server said is kept for the details, as `error` or, on the routes
 * that answer the Mastodon way or from the framework, `message`.
 *
 * @param {object} error what axios threw
 * @param {string} fallback what to say when the code is none this page knows
 * @return {{text: string, details: string}} the sentence, and the server's own words for the details
 */
export function errorOf(error, fallback) {
	const data = error?.response?.data
	const said = typeof data?.error === 'string' ? data.error : data?.message

	return {
		text: errorSentence(data?.code) ?? fallback,
		details: typeof said === 'string' ? said : '',
	}
}

/**
 * Why a move stopped, from the move as the server keeps it.
 *
 * @param {{error?: string, progress?: {code?: string}}} move the move
 * @return {{text: string, details: string}} the sentence, and the server's own words for the details
 */
export function stoppedMoveError(move) {
	return {
		text: errorSentence(move?.progress?.code) ?? SENTENCES['move-failed'](),
		details: move?.error ?? '',
	}
}

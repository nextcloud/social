import { h, resolveComponent } from 'vue'
import Emoji from './Emoji.vue'

export default {
	name: 'MessageContent',
	props: {
		item: {
			type: Object,
			required: true,
		},
	},
	render() {
		const routerLink = resolveComponent('router-link')
		return formatMessage(h, routerLink, this.item)
	},
}

export function formatMessage(hFn, routerLink, item) {
	if (!item.tags) {
		item.tags = []
	}
	const parser = new DOMParser()
	const dom = parser.parseFromString(`<div id="rootwrapper">${item.content}</div>`, 'text/html')
	const element = dom.getElementById('rootwrapper')
	const cleaned = cleanCopy(hFn, routerLink, element, item)
	return cleaned
}

function domToVue(hFn, routerLink, node, context) {
	switch (node.tagName) {
	case 'P':
		return cleanCopy(hFn, routerLink, node, context)
	case 'BR':
		return cleanCopy(hFn, routerLink, node, context)
	case 'SPAN':
		return cleanCopy(hFn, routerLink, node, context)
	case 'A':
		return cleanLink(hFn, routerLink, node, context)
	default:
		return transformText(hFn, routerLink, node.textContent ?? '')
	}
}

const mentionRegex = /(\W|^)((@\w+)@[\w.\-_]+)/ig
const hashTagRegex = /(\W|^)(#\w+)/i

function transformText(hFn, routerLink, text) {
	return transformTextRegex(text, [
		{
			regex: mentionRegex,
			onMatch: match => [
				match[1],
				hFn(routerLink,
					{
						to: {
							name: 'profile',
							params: { account: match[2].slice(1) },
						},
					},
					[match[3]]
				),
			],
		},
		{
			regex: hashTagRegex,
			onMatch: match => [
				match[1],
				hFn(routerLink,
					{
						to: {
							name: 'tags',
							params: { tag: match[2].slice(1) },
						},
					},
					[match[2]]
				),
			],
		},
		{
			regex: emojiRe,
			onMatch: match => hFn(
				Emoji,
				{
					emoji: match[0],
				}
			),
		},
	])
}

function cleanCopy(hFn, routerLink, node, context) {
	const children = Array.from(node.childNodes).map(node => domToVue(hFn, routerLink, node, context))
	return hFn(node.tagName, children)
}

function cleanLink(hFn, routerLink, node, context) {
	const type = getLinkType(node.className)
	const attributes = {}
	const tag = matchMention(context.mentions, node.getAttribute('href') ?? '', node.textContent ?? '')

	switch (type) {
	case 'mention':
		if (tag) {
			attributes.rel = 'nofollow noopener noreferrer'
			attributes.target = '_blank'
			attributes.href = node.getAttribute('href')
			attributes.title = tag.name

			return hFn('a', attributes, [transformText(hFn, routerLink, node.textContent)])
		} else {
			return transformText(hFn, routerLink, node.textContent)
		}
	case 'hashtag':
		return hFn(
			routerLink,
			{
				to: {
					name: 'tags',
					params: { tag: node.textContent?.slice(1) },
				},
			},
			[node.textContent]
		)
	default:
		attributes.rel = 'nofollow noopener noreferrer'
		attributes.target = '_blank'
		attributes.href = node.getAttribute('href')

		return hFn('a', attributes, [transformText(hFn, routerLink, node.textContent)])
	}
}

function getLinkType(className) {
	const parts = className.split(' ')
	if (parts.includes('hashtag')) {
		return 'hashtag'
	}
	if (parts.includes('mention')) {
		return 'mention'
	}
	return ''
}

function matchMention(tags = [], mentionHref, mentionText) {
	const mentionUrl = new URL(mentionHref)
	for (const tag of tags) {
		if (mentionText === tag.acct) {
			return tag
		}

		const tagUrl = new URL(tag.url)
		if (tagUrl.host === mentionUrl.host) {
			const [, name] = tag.acct.split('@')
			if (name === mentionText || '@' + name === mentionText) {
				return tag
			}
		}
	}
	return null
}

// eslint-disable-next-line
const emojiRe = /(?:\ud83d\udc68\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffc-\udfff]|\ud83d\udc68\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffd-\udfff]|\ud83d\udc68\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc68\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffd\udfff]|\ud83d\udc68\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffe]|\ud83d\udc69\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffc-\udfff]|\ud83d\udc69\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffc-\udfff]|\ud83d\udc69\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffd-\udfff]|\ud83d\udc69\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb\udffd-\udfff]|\ud83d\udc69\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc69\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb\udffc\udffe\udfff]|\ud83d\udc69\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffd\udfff]|\ud83d\udc69\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb-\udffd\udfff]|\ud83d\udc69\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc68\ud83c[\udffb-\udffe]|\ud83d\udc69\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83d\udc69\ud83c[\udffb-\udffe]|\ud83e\uddd1\ud83c\udffb\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffc\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffd\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udffe\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\ud83c\udfff\u200d\ud83e\udd1d\u200d\ud83e\uddd1\ud83c[\udffb-\udfff]|\ud83e\uddd1\u200d\ud83e\udd1d\u200d\ud83e\uddd1|\ud83d\udc6b\ud83c[\udffb-\udfff]|\ud83d\udc6c\ud83c[\udffb-\udfff]|\ud83d\udc6d\ud83c[\udffb-\udfff]|\ud83d[\udc6b-\udc6d])|(?:\ud83d[\udc68\udc69]|\ud83e\uddd1)(?:\ud83c[\udffb-\udfff])?\u200d(?:\u2695\ufe0f|\u2696\ufe0f|\u2708\ufe0f|\ud83c[\udf3e\udf73\udf7c\udf84\udf93\udfa4\udfa8\udfbb\udfe4]|\ud83d[\udc66-\udc69\udc6e\udc71\udc73\udc77\udc81\udc82\udc86\udc87\udcde\udd25\uddde\udde0\udde2\udde3\udde4\udde5\udde6\uddf3\udfeb\udfed]|\ud83e[\udd0f\udd1a\udd1c\udd20-\udd2d\udd35-\udd39\udd3b-\udd3e\udd40-\udd45\udd47-\udd4b\udd4c\udd4e\udd50-\udd58\udd5a-\udd62\udd64-\udd67\udd69-\udd6c\udd6f-\udd70\udd73-\udd76\udd78-\udd79\udd7c\udd7d\udd80-\udd86\udd88\udd8b-\udd8d\udd8f-\udd93\udd95\udd96\udd98\udda1\udda2\udda5\udda6\udda9\uddab\uddac\uddb0-\uddb2\uddb5\uddb8\uddb9\uddbc\uddbd\uddbf\uddce\uddc0-\uddc5\uddc7\uddcd\uddd0\uddd2-\uddd5\udde3\udde4\udde6\udde8\uddea\uddec-\uddef\uddf3\uddfa\uddfc\uddfe])/

function transformTextRegex(text, handlers) {
	const parts = []

	while (text.length > 0) {
		const result = handlers.reduce((bestMatch, handler) => {
			let match
			if ((match = handler.regex.exec(text))) {
				if (bestMatch.index === -1 || match.index < bestMatch.index) {
					return {
						index: match.index,
						match,
						onMatch: handler.onMatch,
					}
				}
			}
			return bestMatch
		}, { index: -1 })

		if (result.index !== -1) {
			if (result.index > 0) {
				parts.push(text.slice(0, result.index))
			}

			parts.push(result.onMatch(result.match))
			text = text.slice(result.index + result.match[0].length)
		} else {
			parts.push(text)
			return parts
		}
	}

	return parts
}

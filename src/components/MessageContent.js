import Vue from 'vue'

export default Vue.component('MessageContent', {
	props: {
		source: {
			type: Object,
			required: true
		}
	},
	render: function(createElement) {
		return formatMessage(createElement, this.source)
	}
})

/**
 * Transform the message source into Vue elements
 *
 * filters out all tags except <br/>, <p>, <span> and <a>.
 *
 * Links that are hashtags or mentions are rewritten to link to the local profile or hashtag page
 * All external links have `rel="nofollow noopener noreferrer"` and `target="_blank"` set.
 *
 * All attributes other than `href` for links are stripped from the source
 */
export function formatMessage(createElement, source) {
	let mentions = source.tag.filter(tag => tag.type === 'Mention')
	let hashtags = source.tag.filter(tag => tag.type === 'Hashtag')

	let parser = new DOMParser()
	let dom = parser.parseFromString(`<div id="rootwrapper">${source.content}</div>`, 'text/html')
	let element = dom.getElementById('rootwrapper')
	let cleaned = cleanCopy(createElement, element, { mentions, hashtags })
	return cleaned
}

function domToVue(createElement, node, context) {
	switch (node.tagName) {
	case 'P':
		return cleanCopy(createElement, node, context)
	case 'BR':
		return cleanCopy(createElement, node, context)
	case 'SPAN':
		return cleanCopy(createElement, node, context)
	case 'A':
		return cleanLink(createElement, node, context)
	default:
		return node.textContent
	}
}

/**
 * copy a node without any attributes and cleaning all children
 */
function cleanCopy(createElement, node, context) {
	let children = Array.from(node.childNodes).map(node => domToVue(createElement, node, context))
	return createElement(node.tagName, children)
}

function cleanLink(createElement, node, context) {
	let type = getLinkType(node.className)
	let attributes = {}

	switch (type) {
	case 'mention':
		let tag = matchMention(context.mentions, node.getAttribute('href'), node.textContent)
		if (tag) {
			attributes['href'] = OC.generateUrl(`apps/social/${tag.name}`)
		} else {
			return node.textContent
		}
		break
	case 'hashtag':
		attributes['href'] = OC.generateUrl(`apps/social/timeline/tags/${node.textContent}`)
		break
	default:
		attributes['rel'] = 'nofollow noopener noreferrer'
		attributes['target'] = '_blank'
		attributes['href'] = node.getAttribute('href')
	}

	return createElement('a', { attrs: attributes }, [node.textContent])
}

function getLinkType(className) {
	let parts = className.split(' ')
	if (parts.includes('hashtag')) {
		return 'hashtag'
	}
	if (parts.includes('mention')) {
		return 'mention'
	}
	return ''
}

function matchMention(tags, mentionHref, mentionText) {
	let mentionUrl = new URL(mentionHref)
	for (let tag of tags) {
		if (mentionText === tag.name) {
			return tag
		}

		// since the mention link href is not always equal to the href in the tag
		// we instead match the server and username separate
		let tagUrl = new URL(tag.href)
		if (tagUrl.host === mentionUrl.host) {
			let [, name] = tag.name.split('@')
			if (name === mentionText || '@' + name === mentionText) {
				return tag
			}
		}
	}
	return null
}

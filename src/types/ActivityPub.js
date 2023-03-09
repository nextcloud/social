/**
 * @copyright Copyright (c) 2023 Louis Chmn <louis@chmn.me>
 *
 * @author Louis Chmn <louis@chmn.me>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * @typedef APObject - https://www.w3.org/TR/activitystreams-vocabulary/#dfn-object
 * @property {string} id -
 * @property {string} type - Ex: 'Object'
 * @property {APObject|APLink[]} attachment -
 * @property {APObject|APLink[]} attributedTo - Ex: ["canonical", "preview"]
 * @property {APObject|APLink[]} audience - Ex: ["canonical", "preview"]
 * @property {string} content - The content or textual representation of the Object encoded as a JSON string.
 * @property {Object<string, string>} contentMap - Language-tagged values for translated content.
 * @property {APObject|APLink} context - Identifies the context within which the object exists or an activity was performed.
 * @property {string} name - A simple, human-readable, plain-text name for the object.
 * @property {Object<string, string>} nameMap - Language-tagged values for translated name.
 * @property {string} endTime - Ex: "2015-01-01T06:00:00-08:00"
 * @property {APObject|APLink} generator - Identifies the entity (e.g. an application) that generated the object.
 * @property {APObject|APLink} icon -
 * @property {APObject} image -
 * @property {APObject|APLink} inReplyTo -
 * @property {APObject|APLink} location -
 * @property {APObject|APLink} preview -
 * @property {string} published - Ex: "2015-01-01T06:00:00-08:00"
 * @property {APCollection} replies -
 * @property {string} startTime - Ex: "2015-01-01T06:00:00-08:00"
 * @property {string} summary -
 * @property {(APObject|APLink)[]} tag -
 * @property {string} updated - Ex: "2015-01-01T06:00:00-08:00"
 * @property {string} url -
 * @property {APObject|APLink} to -
 * @property {APObject|APLink} bto -
 * @property {APObject|APLink} cc -
 * @property {APObject|APLink} bcc -
 * @property {string} mediaType - MIME Media Type. Ex: "text/html"
 * @property {string} duration - Ex: "PT2H"
 */

/**
 * @typedef APLink - https://www.w3.org/TR/activitystreams-vocabulary/#dfn-link
 * @property {'Link'} type - 'Link'
 * @property {string} href - The target resource pointed to by a Link. Ex: "http://example.org/abc"
 * @property {string[]} ref - Ex: ["canonical", "preview"]
 * @property {string} mediaType - MIME Media Type. Ex: "text/html"
 * @property {string} name - Ex: "An example name"
 * @property {string} hrefLang - A [BCP47] Language-Tag. Ex: "en"
 * @property {number} height - Ex: 100
 * @property {number} width - Ex: 100
 * @property {APObject|APLink} preview - Identifies an entity that provides a preview of this object.
 */

/**
 * @typedef {APObject} APCollection
 * @property {(APObject|APLink)[]} items -
 */

export default {}

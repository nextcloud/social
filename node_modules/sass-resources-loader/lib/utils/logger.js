'use strict';

Object.defineProperty(exports, "__esModule", {
  value: true
});

var _chalk = require('chalk');

var _chalk2 = _interopRequireDefault(_chalk);

function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }

function _toConsumableArray(arr) { if (Array.isArray(arr)) { for (var i = 0, arr2 = Array(arr.length); i < arr.length; i++) { arr2[i] = arr[i]; } return arr2; } else { return Array.from(arr); } } /* eslint no-console: 0 */

exports.default = {
  log: function log() {
    var _console;

    for (var _len = arguments.length, output = Array(_len), _key = 0; _key < _len; _key++) {
      output[_key] = arguments[_key];
    }

    var pettyOutput = [_chalk2.default.yellow('[sass-resources-loader]: ')].concat(output, '\n');
    (_console = console).log.apply(_console, _toConsumableArray(pettyOutput));
  },
  debug: function debug() {
    if (__DEBUG__) this.log.apply(this, arguments);
  },
  error: function error() {
    var _console2;

    for (var _len2 = arguments.length, output = Array(_len2), _key2 = 0; _key2 < _len2; _key2++) {
      output[_key2] = arguments[_key2];
    }

    var errorOutput = [_chalk2.default.red('[sass-resources-loader]: ')].concat(output, '\n');
    (_console2 = console).log.apply(_console2, _toConsumableArray(errorOutput));
  }
};
'use strict';

Object.defineProperty(exports, "__esModule", {
  value: true
});

var _glob = require('glob');

var _glob2 = _interopRequireDefault(_glob);

var _logger = require('./logger');

var _logger2 = _interopRequireDefault(_logger);

var _isArrayOfStrings = require('./isArrayOfStrings');

var _isArrayOfStrings2 = _interopRequireDefault(_isArrayOfStrings);

var _flattenArray = require('./flattenArray');

var _flattenArray2 = _interopRequireDefault(_flattenArray);

function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }

exports.default = function (locations) {
  if (typeof locations === 'string') {
    _logger2.default.debug('options.resources is String:', true);
    return _glob2.default.sync(locations);
  }

  if ((0, _isArrayOfStrings2.default)(locations)) {
    _logger2.default.debug('options.resources is Array of Strings:', true);
    var paths = locations.map(function (file) {
      return _glob2.default.sync(file);
    });
    return (0, _flattenArray2.default)(paths);
  }

  return [];
};
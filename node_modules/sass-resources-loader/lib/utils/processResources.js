'use strict';

Object.defineProperty(exports, "__esModule", {
  value: true
});

exports.default = function (error, resources, source, module, callback) {
  if (error) {
    _logger2.default.debug('Resources: **not found**');
    return callback(error);
  }

  var stringifiedResources = (Array.isArray(resources) ? resources.join('\n') : resources) + '\n';

  var output = stringifiedResources + source;

  _logger2.default.debug('Resources: \n', '/* ' + module + ' */ \n', output);

  return callback(null, output);
};

var _logger = require('./logger');

var _logger2 = _interopRequireDefault(_logger);

function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }
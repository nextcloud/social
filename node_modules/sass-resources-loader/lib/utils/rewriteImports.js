'use strict';

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.getRelativeImportPath = undefined;

var _path = require('path');

var _path2 = _interopRequireDefault(_path);

var _logger = require('./logger');

var _logger2 = _interopRequireDefault(_logger);

function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }

var importRegexp = /@import\s+(?:'([^']+)'|"([^"]+)"|([^\s;]+))/g;

var getRelativeImportPath = exports.getRelativeImportPath = function getRelativeImportPath(oldImportPath, absoluteImportPath, moduleContext) {
  // from node_modules
  if (/^\~/.test(oldImportPath)) {
    return oldImportPath;
  }
  return _path2.default.relative(moduleContext, absoluteImportPath);
};

exports.default = function (error, file, contents, moduleContext, callback) {
  if (error) {
    _logger2.default.debug('Resources: **not found**');
    return callback(error);
  }

  if (!/\.s[ac]ss$/i.test(file)) {
    return callback(null, contents);
  }

  var rewritten = contents.replace(importRegexp, function (entire, single, double, unquoted) {
    var oldImportPath = single || double || unquoted;

    var absoluteImportPath = _path2.default.join(_path2.default.dirname(file), oldImportPath);
    var relImportPath = getRelativeImportPath(oldImportPath, absoluteImportPath, moduleContext);
    var newImportPath = relImportPath.split(_path2.default.sep).join('/');
    _logger2.default.debug('Resources: @import of ' + oldImportPath + ' changed to ' + newImportPath);

    var lastCharacter = entire[entire.length - 1];
    var quote = lastCharacter === "'" || lastCharacter === '"' ? lastCharacter : '';

    return '@import ' + quote + newImportPath + quote;
  });

  callback(null, rewritten);
};
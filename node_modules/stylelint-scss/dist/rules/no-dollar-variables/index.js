"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.messages = exports.ruleName = undefined;

exports.default = function (value) {
  return function (root, result) {
    var validOptions = _stylelint.utils.validateOptions(result, ruleName, {
      actual: value
    });

    if (!validOptions) {
      return;
    }

    root.walkDecls(function (decl) {
      if (decl.prop[0] !== "$") {
        return;
      }

      _stylelint.utils.report({
        message: messages.rejected(decl.prop),
        node: decl,
        result: result,
        ruleName: ruleName
      });
    });
  };
};

var _stylelint = require("stylelint");

var _utils = require("../../utils");

var ruleName = exports.ruleName = (0, _utils.namespace)("no-dollar-variables");

var messages = exports.messages = _stylelint.utils.ruleMessages(ruleName, {
  rejected: function rejected(variable) {
    return "Unexpected dollar variable " + variable;
  }
});
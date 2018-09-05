"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.messages = exports.ruleName = undefined;

exports.default = function (value, secondaryOptions) {
  return function (root, result) {
    var validOptions = _stylelint.utils.validateOptions(result, ruleName, {
      actual: value
    }, {
      actual: secondaryOptions,
      possible: {
        ignoreInside: ["at-rule", "nested-at-rule"],
        ignoreInsideAtRules: [_lodash.isString]
      },
      optional: true
    });

    if (!validOptions) {
      return;
    }

    var vars = {};

    root.walkDecls(function (decl) {
      var isVar = decl.prop[0] === "$";
      var isInsideIgnoredAtRule = decl.parent.type === "atrule" && secondaryOptions && secondaryOptions.ignoreInside && secondaryOptions.ignoreInside === "at-rule";
      var isInsideIgnoredNestedAtRule = decl.parent.type === "atrule" && decl.parent.parent.type !== "root" && secondaryOptions && secondaryOptions.ignoreInside && secondaryOptions.ignoreInside === "nested-at-rule";
      var isInsideIgnoredSpecifiedAtRule = decl.parent.type === "atrule" && secondaryOptions && secondaryOptions.ignoreInsideAtRules && secondaryOptions.ignoreInsideAtRules.indexOf(decl.parent.name) > -1;

      if (!isVar || isInsideIgnoredAtRule || isInsideIgnoredNestedAtRule || isInsideIgnoredSpecifiedAtRule) {
        return;
      }

      if (vars[decl.prop]) {
        _stylelint.utils.report({
          message: messages.rejected(decl.prop),
          node: decl,
          result: result,
          ruleName: ruleName
        });
      }

      vars[decl.prop] = true;
    });
  };
};

var _stylelint = require("stylelint");

var _lodash = require("lodash");

var _utils = require("../../utils");

var ruleName = exports.ruleName = (0, _utils.namespace)("no-duplicate-dollar-variables");

var messages = exports.messages = _stylelint.utils.ruleMessages(ruleName, {
  rejected: function rejected(variable) {
    return "Unexpected duplicate dollar variable " + variable;
  }
});
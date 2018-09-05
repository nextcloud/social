"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});

exports.default = function (multidimensional) {
  return [].concat.apply([], multidimensional);
};
'use strict';

Object.defineProperty(exports, "__esModule", {
  value: true
});

exports.default = function (array) {
  return Array.isArray(array) && array.every(function (item) {
    return typeof item === 'string';
  });
};
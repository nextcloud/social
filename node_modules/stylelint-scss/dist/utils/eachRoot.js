"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});

exports.default = function (root, cb) {
  // class `Document` is a part of `postcss-html`,
  // It is collection of roots in HTML File.
  // See: https://github.com/gucong3000/postcss-html/blob/master/lib/document.js
  if (root.constructor.name === "Document") {
    root.nodes.forEach(cb);
  } else {
    cb(root);
  }
};
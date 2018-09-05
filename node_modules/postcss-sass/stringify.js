var Stringifier = require('./stringifier');

module.exports = function stringify(node, builder) {
    var str = new Stringifier(builder);
    str.stringify(node);
};

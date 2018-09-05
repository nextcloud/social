var Stringifier = require('postcss/lib/stringifier');

var SassStringifier = function (builder) {
    Stringifier.call(this, builder);
};

const DEFAULT_RAW = {
    colon:        ': ',
    commentLeft:  ' ',
    commentRight: ' '
};

SassStringifier.prototype = Object.create(Stringifier.prototype);
SassStringifier.prototype.constructor = Stringifier;

SassStringifier.prototype.has = function has(value) {
    return typeof value !== 'undefined';
};

SassStringifier.prototype.block = function (node, start) {
    var between = node.raws.sssBetween || '';
    this.builder(start + between, node, 'start');
    if (this.has(node.nodes)) {
        this.body(node);
    }
};

SassStringifier.prototype.decl = function (node) {
    var between = node.raws.between || DEFAULT_RAW.colon;
    var string  = node.prop + between + this.rawValue(node, 'value');
    if (node.important) {
        string += '!important';
    }
    this.builder(string, node);
};

SassStringifier.prototype.comment = function (node) {
    var left  = this.has(node.raws.left) ?
        node.raws.left : DEFAULT_RAW.commentLeft;
    var right = this.has(node.raws.right) ?
        node.raws.right : DEFAULT_RAW.commentRight;

    if (node.raws.commentType === 'single') {
        this.builder('//' + left + node.text + right, node);
    } else if (node.raws.commentType === 'multi') {
        this.builder('/*' + left + node.text + right + '*/', node);
    }
};


module.exports = SassStringifier;

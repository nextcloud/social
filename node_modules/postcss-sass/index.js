var postcss = require('postcss');
var gonzales = require('gonzales-pe');
var Input = require('postcss/lib/input');

var DEFAULT_RAWS_ROOT = {
    before:    ''
};

var DEFAULT_RAWS_RULE = {
    before:    '',
    between:   ''
};

var DEFAULT_RAWS_DECL = {
    before:    '',
    between:   '',
    semicolon: false
};

var DEFAULT_COMMENT_DECL = {
    before:    '',
    left:      '',
    right:     ''
};

global.postcssSass = {};

function process(
    source,
    node,
    parent,
    input
) {
    if (node.type === 'stylesheet') {
        // Create and set parameters for Root node
        var root = postcss.root();
        root.source = {
            start: node.start,
            end: node.end,
            input: input
        };
        // Raws for root node
        root.raws = {
            semicolon: DEFAULT_RAWS_ROOT.semicolon,
            before: DEFAULT_RAWS_ROOT.before
        };
        // Store spaces before root (if exist)
        global.postcssSass.before = '';
        for (var i = 0; i < node.content.length; i++) {
            process(source, node.content[i], root, input);
        }
        return root;
    } else if (node.type === 'ruleset') {
        // Loop to find the deepest ruleset node
        var pseudoClassFirst = false;
        // Define new selector
        var selector = '';
        global.postcssSass.multiRuleProp = '';
        for (var rContent = 0; rContent < node.content.length; rContent++ ) {
            if (node.content[rContent].type === 'block') {
                // Create Rule node
                var rule = postcss.rule();

                // Object to store raws for Rule
                var rRaws = {
                    before: global.postcssSass.before ||
                        DEFAULT_RAWS_RULE.before,
                    between: DEFAULT_RAWS_RULE.between
                };

                /* Variable to store spaces and symbols
                 before declaration property */
                global.postcssSass.before = '';

                global.postcssSass.comment = false;

                // Look up throw all nodes in current ruleset node
                for (
                    var rCurrentContent = 0;
                    rCurrentContent < node.content.length;
                    rCurrentContent++
                ) {
                    if (node.content[rCurrentContent].type === 'block') {
                        process(
                            source,
                            node.content[rCurrentContent],
                            rule,
                            input
                        );
                    }
                }

                if (rule.nodes.length !== 0) {
                    // Write selector to Rule, and remove last whitespace
                    rule.selector = selector;
                    // Set parameters for Rule node
                    rule.parent = parent;
                    rule.source = {
                        start: node.start,
                        end: node.end,
                        input: input
                    };
                    rule.raws = rRaws;
                    parent.nodes.push(rule);
                }
            } else if (node.content[rContent].type === 'selector') {
                // Creates selector for rule
                for (
                    var sCurrentContent = 0;
                    sCurrentContent < node.content[rContent].length;
                    sCurrentContent++
                ) {
                    if (node.content[rContent]
                        .content[sCurrentContent].type === 'id') {
                        selector += '#';
                    } else if (node.content[rContent]
                        .content[sCurrentContent].type === 'class') {
                        selector += '.';
                    } else if (node.content[rContent]
                        .content[sCurrentContent].type === 'typeSelector') {
                        if (node.content[rContent]
                            .content[sCurrentContent + 1] &&
                            node.content[rContent]
                                .content[sCurrentContent + 1]
                                .type === 'pseudoClass' &&
                            pseudoClassFirst) {
                            selector += ', ';
                        } else {
                            pseudoClassFirst = true;
                        }
                    } else if (node.content[rContent]
                        .content[sCurrentContent].type === 'pseudoClass') {
                        selector += ':';
                    }
                    selector += node.content[rContent]
                        .content[sCurrentContent].content;
                }
            }
        }
    } else if (node.type === 'block') {
        /* If nested rules exist,
        wrap current rule in new rule node */
        if (global.postcssSass.multiRule) {
            var multiRule = postcss.rule();
            multiRule.source = {
                start: {
                    line: node.start.line - 1,
                    column: node.start.column
                },
                end: node.end,
                input: input
            };
            multiRule.parent = parent;
            multiRule.selector = global.postcssSass.multiRuleProp;
            multiRule.raws = {
                before: global.postcssSass.before || DEFAULT_RAWS_RULE.before,
                between: DEFAULT_RAWS_RULE.between
            };
            parent.push(multiRule);
            parent = multiRule;
        }

        global.postcssSass.before = '';

        // Looking for declaration node in block node
        for (var bContent = 0; bContent < node.content.length; bContent++) {
            process(
                source,
                node.content[bContent],
                parent,
                input
            );
        }
    } else if (node.type === 'declaration') {
        var isBlockInside = false;
        // Create Declaration node
        var decl = postcss.decl();
        decl.prop = '';
        // Object to store raws for Declaration
        var dRaws = {
            before: global.postcssSass.before || DEFAULT_RAWS_DECL.before,
            between: DEFAULT_RAWS_DECL.between,
            semicolon: DEFAULT_RAWS_DECL.semicolon
        };

        global.postcssSass.property = false;
        global.postcssSass.betweenBefore = false;
        global.postcssSass.comment = false;
        // Looking for property and value node in declaration node
        for (var dContent = 0; dContent < node.content.length; dContent++) {
            if (node.content[dContent].type === 'property') {
                /* global.property to detect is property is
                already defined in current object */
                global.postcssSass.property = true;
                global.postcssSass.multiRuleProp = node.content[dContent]
                    .content[0].content;
                process(
                    source,
                    node.content[dContent],
                    decl,
                    input
                );
            } else if (node.content[dContent].type === 'propertyDelimiter') {
                if (global.postcssSass.property &&
                    !global.postcssSass.betweenBefore) {
                    /* If property is already defined and
                     there's no ':' before it */
                    dRaws.between += node.content[dContent].content;
                    global.postcssSass.multiRuleProp += node.content[dContent]
                        .content;
                } else {
                    /* If ':' goes before property declaration, like
                    * :width 100px */
                    global.postcssSass.betweenBefore = true;
                    dRaws.before += node.content[dContent].content;
                    global.postcssSass.multiRuleProp += node.content[dContent]
                        .content;
                }
            } else if (node.content[dContent].type === 'space') {
                dRaws.between += node.content[dContent].content;
            } else if (node.content[dContent].type === 'value') {
                // Look up for a value for current property
                if (node.content[dContent].content[0].type === 'block') {
                    isBlockInside = true;
                    // If nested rules exist
                    if (typeof node.content[dContent]
                        .content[0].content === 'object') {
                        global.postcssSass.multiRule = true;
                    }
                    process(
                        source,
                        node.content[dContent].content[0],
                        parent,
                        input
                    );
                } else if (node.content[dContent]
                    .content[0].type === 'variable') {
                    decl.value = '$';
                    process(
                        source,
                        node.content[dContent],
                        decl,
                        input
                    );
                } else if (node.content[dContent].content[0].type === 'color') {
                    decl.value = '#';
                    process(
                        source,
                        node.content[dContent],
                        decl,
                        input
                    );
                } else if (node.content[dContent]
                    .content[0].type === 'number') {
                    if (node.content[dContent].content.length > 1) {
                        decl.value = '';
                        for (
                            var dCurrentContent = 0;
                            dCurrentContent < node.content[dContent]
                                .content.length;
                            dCurrentContent++
                        ) {
                            decl.value += node.content[dContent]
                                .content[dCurrentContent];
                        }
                    } else {
                        process(
                            source,
                            node.content[dContent],
                            decl,
                            input
                        );
                    }
                } else {
                    process(
                        source,
                        node.content[dContent],
                        decl,
                        input
                    );
                }
            }
        }

        global.postcssSass.before = '';

        if (!isBlockInside) {
            // Set parameters for Declaration node
            decl.source = {
                start: node.start,
                end: node.end,
                input: input
            };
            decl.parent = parent;
            decl.raws = dRaws;
            parent.nodes.push(decl);
        }
    } else if (node.type === 'property') {
        // Set property for Declaration node
        if (node.content[0].type === 'variable') {
            parent.prop += '$';
        }
        parent.prop += node.content[0].content;
    } else if (node.type === 'value') {
        if (!parent.value) {
            parent.value = '';
        }
        // Set value for Declaration node
        if (node.content.length > 0) {
            for (
                var vContent = 0;
                vContent < node.content.length;
                vContent++
            ) {
                if (node.content[vContent].type === 'important') {
                    parent.important = true;
                } else if (node.content[vContent]
                    .content.constructor === Array ) {
                    for (
                        var vContentParts = 0;
                        vContentParts < node.content[vContent]
                            .content.length;
                        vContentParts++
                    ) {
                        parent.value += node.content[vContent]
                            .content[vContentParts];
                    }
                } else {
                    parent.value += node.content[vContent].content;
                }
            }
        } else if (node.content[0].content.constructor === Array) {
            for (
                var vContentFirst = 0;
                vContentFirst < node.content[0].content.length;
                vContentFirst++
            ) {
                parent.value += node.content[0]
                    .content[vContentFirst].content;
            }
        } else {
            parent.value += node.content[0].content;
        }
    } else if (node.type === 'singlelineComment' ||
        node.type === 'multilineComment') {
        // Create a new node for comment
        var comment = postcss.comment();
        var text = node.content;
        // Clear comment text from spaces/symbols
        var textClear = text.trim();
        comment.text = textClear;
        // Found spaces/symbols before comment
        var left = text.search(/\S/);
        global.postcssSass.comment = true;
        // Found spaces/symbols after comment
        var right = text.length - textClear.length - left;
        // Raws for current comment node
        comment.raws = {
            before: global.postcssSass.before || DEFAULT_COMMENT_DECL.before,
            left: new Array(left + 1).join(' '),
            right: new Array(right + 1).join(' ')
        };
        // Define type of comment
        if (node.type === 'singlelineComment') {
            comment.raws.commentType = 'single';
        } else if (node.type === 'multilineComment') {
            comment.raws.commentType = 'multi';
        }
        parent.nodes.push(comment);
    } else if (node.type === 'space') {
        // Spaces before root and rule
        if (parent.type === 'root') {
            global.postcssSass.before += node.content;
        } else if (parent.type === 'rule') {
            if (global.postcssSass.comment) {
                global.postcssSass.before = '\n' + node.content;
            } else {
                if (global.postcssSass.before === '') {
                    global.postcssSass.before = '\n';
                }
                global.postcssSass.before += node.content;
            }
        }
    } else if (node.type === 'declarationDelimiter') {
        global.postcssSass.before += node.content;
    }
    return null;
}

module.exports = function sassToPostCssTree(
    source,
    opts
) {
    var data = {
        node: gonzales.parse(source.toString('utf8'), { syntax: 'sass' }),
        input: new Input(source, opts),
        parent: null
    };
    return process(
        source,
        data.node,
        data.parent,
        data.input);
};

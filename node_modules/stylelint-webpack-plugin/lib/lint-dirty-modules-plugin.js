const assign = require('object-assign');
const { defaultTo } = require('ramda');
const micromatch = require('micromatch');
const runCompilation = require('./run-compilation');

/**
 * Binds callback with provided options and stores initial values.
 *
 * @param compiler - webpack compiler object
 * @param options - stylelint nodejs options
 */
function LintDirtyModulesPlugin(compiler, options) {
  this.startTime = Date.now();
  this.prevTimestamps = {};
  this.isFirstRun = true;
  this.compiler = compiler;
  this.options = options;

  // bind(this) is here to prevent context overriding by webpack
  compiler.plugin('emit', this.lint.bind(this));
}

/**
 * Lints changed files provided by compilation object.
 * Fully executed only after initial run.
 *
 * @param compilation - webpack compilation object
 * @param callback - to be called when execution is done
 * @returns {*}
 */
// eslint-disable-next-line consistent-return
LintDirtyModulesPlugin.prototype.lint = function lint(compilation, callback) {
  if (this.isFirstRun) {
    this.isFirstRun = false;
    this.prevTimestamps = compilation.fileTimestamps;
    return callback();
  }

  const dirtyOptions = assign({}, this.options);
  const glob = dirtyOptions.files.join('|');
  const changedFiles = this.getChangedFiles(compilation.fileTimestamps, glob);
  this.prevTimestamps = compilation.fileTimestamps;

  if (changedFiles.length) {
    dirtyOptions.files = changedFiles;
    runCompilation.call(this, dirtyOptions, this.compiler, callback);
  } else {
    callback();
  }
};

/**
 * Returns an array of changed files comparing current timestamps
 * against cached timestamps from previous run.
 *
 * @this plugin - stylelint-webpack-plugin this scope
 * @param fileTimestamps - an object with keys as filenames and values as their timestamps.
 * e.g. {'/filename.scss': 12444222000}
 * @param glob - glob pattern to match files
 * @returns {Array} list of globs that contain changed files
 */
LintDirtyModulesPlugin.prototype.getChangedFiles = function getChangedFiles(
  fileTimestamps,
  glob
) {
  return Object.keys(fileTimestamps).filter(
    (filename) =>
      hasFileChanged.call(this, filename, fileTimestamps[filename]) &&
      micromatch.isMatch(filename, glob)
  );
};

function hasFileChanged(filename, timestamp) {
  return (
    defaultTo(this.startTime)(this.prevTimestamps[filename]) <
    defaultTo(Infinity)(timestamp)
  );
}

module.exports = LintDirtyModulesPlugin;

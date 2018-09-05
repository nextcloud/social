const path = require('path');

const arrify = require('arrify');
const assign = require('object-assign');
const formatter = require('stylelint').formatters.string;

const runCompilation = require('./lib/run-compilation');
const LintDirtyModulesPlugin = require('./lib/lint-dirty-modules-plugin');
const { defaultFilesGlob } = require('./lib/constants');

function apply(options, compiler) {
  options = options || {};
  const context = options.context || compiler.context;

  options = assign(
    {
      formatter,
    },
    options,
    {
      // Default Glob is any directory level of scss and/or sass file,
      // under webpack's context and specificity changed via globbing patterns
      files: arrify(options.files || defaultFilesGlob).map((file) =>
        path.join(context, '/', file)
      ),
      context,
    }
  );

  if (options.lintDirtyModulesOnly) {
    new LintDirtyModulesPlugin(compiler, options); // eslint-disable-line no-new
  } else {
    const runner = runCompilation.bind(this, options);

    if (compiler.hooks) {
      const pluginName = 'StylelintWebpackPlugin';
      compiler.hooks.run.tapAsync(pluginName, runner);
      compiler.hooks.watchRun.tapAsync(pluginName, (compiler, done) => {
        runner(compiler, done);
      });
    } else {
      compiler.plugin('run', runner);
      compiler.plugin('watch-run', (watcher, done) => {
        runner(watcher.compiler, done);
      });
    }
  }
}

/**
 * Pass options to the plugin that get checked and updated before running
 * ref: https://webpack.github.io/docs/plugins.html#the-compiler-instance
 * @param options - from webpack config, see defaults in `apply` function.
 * @return {Object} the bound apply function
 */
module.exports = function stylelintWebpackPlugin(options) {
  return {
    apply: apply.bind(this, options),
  };
};

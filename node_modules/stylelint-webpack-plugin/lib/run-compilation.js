const R = require('ramda');
const linter = require('./linter');
const { errorMessage } = require('./constants');

/**
 * Function bound to the plugin `apply` method to run the linter and report any
 * errors (and their source file locations)
 * @param options - from the apply method, the options passed in
 * @param compiler - the compiler object
 * @param done - webpack callback
 */
module.exports = function runCompilation(options, compiler, done) {
  let errors = [];
  let warnings = [];
  const hooks = {
    latest: 'afterEmit',
    legacy: 'after-emit',
  };

  if (options.emitErrors) {
    hooks.latest = 'afterCompile';
    hooks.legacy = 'after-compile';
  }

  linter(options)
    .then((lint) => {
      const { results } = lint;

      if (options.emitErrors === false) {
        warnings = results.filter(R.either(fileHasErrors, fileHasWarnings));
      } else {
        warnings = results.filter(
          R.both(R.complement(fileHasErrors), fileHasWarnings)
        );
        errors = results.filter(fileHasErrors);
      }

      if (options.failOnError && errors.length) {
        done(new Error(errorMessage));
      } else {
        done();
      }
    })
    .catch(done);

  const callback = (compilation, next) => {
    if (warnings.length) {
      compilation.warnings.push(new Error(options.formatter(warnings)));
      warnings = [];
    }

    if (errors.length) {
      compilation.errors.push(new Error(options.formatter(errors)));
      errors = [];
    }

    next();
  };

  if (compiler.hooks) {
    compiler.hooks[hooks.latest].tapAsync('StylelintWebpackPlugin', callback);
  } else {
    compiler.plugin(hooks.legacy, callback);
  }
};

function fileHasErrors(file) {
  return file.errored;
}

function fileHasWarnings(file) {
  return file.warnings && file.warnings.length;
}

const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  ...defaultConfig,
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, 'dist'),
  },
  cache: {
    type: 'filesystem',
    buildDependencies: {
      config: [__filename],
    },
  },
  resolve: {
    ...defaultConfig.resolve,
    extensions: [...(defaultConfig.resolve?.extensions || []), '.ts', '.tsx'],
    alias: {
      ...(defaultConfig.resolve?.alias || {}),
      '@': path.resolve(__dirname, 'src'),
    },
  },
  watchOptions: {
    ignored: ["**/dist/**"], // Ignore the generated build files to avoid unnecessary rebuilds
  },
};

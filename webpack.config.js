const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const { requestToExternal, requestToHandle } = require('./webpack-dependency-mapping');

module.exports = {
  ...defaultConfig,
  entry: {
    index: './src/index.tsx',
    // Reusable components exposed for add-ons (window.textyComponents).
    components: {
      import: './src/components/index.tsx',
      library: {
        name: 'textyComponents',
        type: 'window',
      },
    },
  },
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
  plugins: [
    // Replace the default dependency-extraction plugin with one that also knows
    // how to externalize Texty's own packages (@texty/*) for add-ons.
    ...defaultConfig.plugins.filter(
      (plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
    ),
    new DependencyExtractionWebpackPlugin({
      requestToExternal,
      requestToHandle,
    }),
  ],
};

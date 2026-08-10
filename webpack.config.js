const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const { requestToExternal, requestToHandle } = require('./webpack-dependency-mapping');

module.exports = {
  ...defaultConfig,
  entry: {
    index: './src/index.tsx',
    // Reusable components exposed for add-ons (window.texty.components).
    components: {
      import: './src/components/index.tsx',
      library: {
        name: ['texty', 'components'],
        type: 'window',
      },
    },
    // The plugin-ui kit exposed for add-ons (window.texty.pluginUi), so lite and
    // every add-on share one copy instead of bundling their own.
    //
    // Both imports are resolved to absolute paths on purpose: the bare
    // '@wedevs/plugin-ui' request is externalized by requestToExternal, so
    // importing it by name here would make this entry externalize to itself.
    // The package's JS comes last — webpack takes the library exports from the
    // last module of a multi-import entry.
    'plugin-ui': {
      import: [
        require.resolve('@wedevs/plugin-ui/styles.css'),
        require.resolve('@wedevs/plugin-ui'),
      ],
      library: {
        name: ['texty', 'pluginUi'],
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

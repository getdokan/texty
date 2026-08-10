# Shared packages (lite → pro)

Texty lite ships two shared JS bundles so add-ons don't bundle their own copy of
the UI kit or of Texty's reusable components.

| Import request      | Global               | Script handle      | Style handle       | Built from                  |
|---------------------|----------------------|--------------------|--------------------|-----------------------------|
| `@wedevs/plugin-ui` | `texty.pluginUi`     | `texty-plugin-ui`  | `texty-plugin-ui`  | `dist/plugin-ui.js` / `.css` |
| `@texty/components` | `texty.components`   | `texty-components` | `texty-components` | `dist/components.js` / `dist/style-components.css` |

Both handles are registered by `Texty\Assets` on `admin_enqueue_scripts`,
`wp_enqueue_scripts` and `login_enqueue_scripts` (priority 5). Nothing is
enqueued — a consumer pulls a bundle in by declaring the handle as a dependency.

## Add-on setup

1. Copy `webpack-dependency-mapping.js` from lite into the add-on and wire it
   into the add-on's webpack config, replacing the default dependency-extraction
   plugin:

   ```js
   const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
   const { requestToExternal, requestToHandle } = require('./webpack-dependency-mapping');

   plugins: [
     ...defaultConfig.plugins.filter(
       (plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
     ),
     new DependencyExtractionWebpackPlugin({ requestToExternal, requestToHandle }),
   ]
   ```

2. Keep `@wedevs/plugin-ui` as a **devDependency** in the add-on — it is only
   needed for types and for the editor; webpack rewrites the import to
   `window.texty.pluginUi` and adds `texty-plugin-ui` to the generated
   `*.asset.php` dependency list.

3. Enqueue as usual — the generated asset file already carries the handles:

   ```php
   $asset = include MY_ADDON_DIR . '/dist/index.asset.php';

   wp_enqueue_script( 'my-addon', MY_ADDON_URL . '/dist/index.js', $asset['dependencies'], $asset['version'], true );
   wp_enqueue_style( 'my-addon', MY_ADDON_URL . '/dist/index.css', [ 'texty-plugin-ui' ], $asset['version'] );
   ```

   Styles are not tracked by dependency extraction — depend on the
   `texty-plugin-ui` (and `texty-components`) **style** handles explicitly.

## Rules

- **Import the package root only.** Deep imports (`@wedevs/plugin-ui/settings`,
  `@wedevs/plugin-ui/utils`) are *not* externalized — they would bundle a second
  copy. Everything in those subpaths is re-exported from the root.
- **Don't import `@wedevs/plugin-ui/styles.css`** in an add-on; depend on the
  `texty-plugin-ui` style handle instead.
- **Render inside `.pui-root`** (or `#texty-app`) — plugin-ui's utilities are
  scoped to those roots.
- **`window.texty` is a shared namespace.** Lite merges its localized data onto
  it (`Object.assign`) rather than assigning, because the shared bundles print
  first. Add-ons must do the same — never `window.texty = {...}`.

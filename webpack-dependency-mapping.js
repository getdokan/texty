/**
 * Maps Texty's shared packages to the globals/handles they are exposed under,
 * so add-ons (e.g. Texty Pro) can `import` them and have webpack externalize the
 * import to the shared runtime (dependency extraction) instead of bundling a
 * second copy.
 *
 * | Import request      | Global               | Script handle      |
 * |---------------------|----------------------|--------------------|
 * | `@texty/components` | `texty.components`   | `texty-components` |
 * | `@wedevs/plugin-ui` | `texty.pluginUi`     | `texty-plugin-ui`  |
 *
 * The mapping applies to Texty's own build as well: `src/**` imports of
 * `@wedevs/plugin-ui` resolve to the shared `texty-plugin-ui` bundle instead of
 * inlining the kit into every entry. The `plugin-ui` entry itself imports the
 * package through an absolute path (see webpack.config.js) so it doesn't get
 * externalized to itself.
 *
 * Only the bare package request is mapped. Deep imports
 * (`@wedevs/plugin-ui/settings`, `@wedevs/plugin-ui/styles.css`, …) keep
 * resolving to the package on disk, because the shared bundle exposes the root
 * export surface only — add-ons should import from `@wedevs/plugin-ui`.
 *
 * Mirrors the Dokan lite → pro pattern.
 */

const SHARED_PACKAGES = {
    '@texty/components': {
        global: [ 'texty', 'components' ],
        handle: 'texty-components',
    },
    '@wedevs/plugin-ui': {
        global: [ 'texty', 'pluginUi' ],
        handle: 'texty-plugin-ui',
    },
};

/**
 * @param {string} request Import request.
 * @return {string[]|undefined} Global variable path the request resolves to.
 */
const requestToExternal = ( request ) => {
    if ( SHARED_PACKAGES[ request ] ) {
        return SHARED_PACKAGES[ request ].global;
    }
};

/**
 * @param {string} request Import request.
 * @return {string|undefined} Script handle the request depends on.
 */
const requestToHandle = ( request ) => {
    if ( SHARED_PACKAGES[ request ] ) {
        return SHARED_PACKAGES[ request ].handle;
    }
};

module.exports = {
    SHARED_PACKAGES,
    requestToExternal,
    requestToHandle,
};

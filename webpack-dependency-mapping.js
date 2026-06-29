/**
 * Maps Texty's reusable packages to the globals/handles they are exposed under,
 * so add-ons (e.g. Texty Pro) can `import` them and have webpack externalize the
 * import to the shared runtime (dependency extraction) instead of bundling a
 * second copy.
 *
 * Mirrors the Dokan lite → pro pattern.
 */

/**
 * @param {string} request Import request.
 * @return {string|undefined} Global variable the request resolves to.
 */
const requestToExternal = ( request ) => {
    if ( request === '@texty/components' ) {
        return [ 'texty', 'components' ];
    }
};

/**
 * @param {string} request Import request.
 * @return {string|undefined} Script handle the request depends on.
 */
const requestToHandle = ( request ) => {
    if ( request === '@texty/components' ) {
        return 'texty-components';
    }
};

module.exports = {
    requestToExternal,
    requestToHandle,
};

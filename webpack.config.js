const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'dist' ),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [
			...( defaultConfig.resolve?.extensions || [] ),
			'.ts',
			'.tsx',
		],
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'@': path.resolve( __dirname, 'src' ),
		},
	},
};

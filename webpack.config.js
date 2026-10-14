const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Due entry: l'app React della pagina di amministrazione e il tracker del frontend (build/tracker/tracker.js).
module.exports = {
	...defaultConfig,
	entry: {
		'admin/index': './assets/admin/index.jsx',
		'tracker/tracker': './assets/tracker/tracker.js',
	},
};

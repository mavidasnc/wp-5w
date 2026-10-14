const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Un solo entry: l'app React della pagina di amministrazione.
module.exports = {
	...defaultConfig,
	entry: {
		'admin/index': './assets/admin/index.jsx',
	},
};

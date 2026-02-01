const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		'poststation-admin': path.resolve(__dirname, 'src/index.jsx'),
	},
	output: {
		path: path.resolve(__dirname, 'build'),
		filename: '[name].js',
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: ['.js', '.jsx', '.json'],
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
	module: {
		...defaultConfig.module,
		rules: [
			{
				test: /\.(js|jsx)$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							'@babel/preset-env',
							['@babel/preset-react', { runtime: 'automatic' }],
						],
					},
				},
			},
			{
				test: /\.css$/,
				use: [
					'style-loader',
					'css-loader',
					'postcss-loader',
				],
			},
		],
	},
};

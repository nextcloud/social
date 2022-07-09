const path = require('path');
const VueLoaderPlugin = require('vue-loader/lib/plugin');


module.exports = {
	entry: {
		social: path.join(__dirname, 'src', 'main.js'),
		ostatus: path.join(__dirname, 'src', 'ostatus.js'),
		'settings-personal': path.join(__dirname, 'src', 'settings-personal.js'),
	},
	output: {
		path: path.resolve(__dirname, './js'),
		publicPath: '/js/',
		filename: '[name].js',
		chunkFilename: '[name].[chunkhash].js'
	},
	module: {
		rules: [
			{
				test: /\.css$/,
				use: [
					'style-loader',
					'css-loader'
				]
			},
			{
				test: /\.scss$/,
				use: [
					'style-loader',
					'css-loader',
					'sass-loader'
				]
			},
			{
				test: /\.vue$/,
				loader: 'vue-loader'
			},
			{
				test: /\.js$/,
				loader: 'babel-loader',
				exclude: /node_modules/
			},
			{
				test: /\.(png|jpg|gif|svg)$/,
				loader: 'file-loader',
				options: {
					name: '[name].[ext]?[hash]'
				}
			}
		]
	},
	plugins: [new VueLoaderPlugin()],
	resolve: {
		extensions: ['*', '.js', '.vue'],
		symlinks: false
	}
};

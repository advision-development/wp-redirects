module.exports = {
	preset: '@wordpress/jest-preset-default',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	transform: {
		'\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
};

module.exports = {
	preset: '@wordpress/jest-preset-default',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	transform: {
		'\\.m?[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
	// @wordpress/components and its deps ship ESM-only builds; transform them.
	transformIgnorePatterns: [
		'/node_modules/(?!(.*/node_modules/)?(@wordpress|uuid)/)',
	],
};

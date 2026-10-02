import { previewRegex } from '../../assets/src/components/QuickAddForm';

describe( 'previewRegex', () => {
	it( 'reports matches case-insensitively', () => {
		expect( previewRegex( '^/blog/(\\d+)$', '/BLOG/12' ) ).toBe(
			'Matches "/BLOG/12" (browser preview)'
		);
	} );
	it( 'reports non-matches', () => {
		expect( previewRegex( '^/blog/(\\d+)$', '/blog/x' ) ).toBe(
			'Does not match "/blog/x" (browser preview)'
		);
	} );
	it( 'handles invalid patterns', () => {
		expect( previewRegex( '(', '/x' ) ).toBe(
			'Pattern is not valid in the browser preview.'
		);
	} );
} );

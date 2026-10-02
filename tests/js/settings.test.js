import {
	formatExtensions,
	parseExtensions,
} from '../../assets/src/utils/settings';

describe( 'extensions', () => {
	it( 'parses a comma list, lowercases, strips dots, drops invalid and duplicates', () => {
		expect( parseExtensions( ' .CSS, js,,bad ext!, png, css ' ) ).toEqual( [
			'css',
			'js',
			'png',
		] );
	} );
	it( 'formats a list for editing', () => {
		expect( formatExtensions( [ 'css', 'js' ] ) ).toBe( 'css, js' );
	} );
} );

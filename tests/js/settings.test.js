import {
	formatExtensions,
	parseExtensions,
	splitSettingsErrors,
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

describe( 'splitSettingsErrors', () => {
	it( 'maps invalid params to their fields', () => {
		expect(
			splitSettingsErrors( {
				code: 'rest_invalid_param',
				data: {
					params: {
						log_404_retention_days:
							'log_404_retention_days must be at least 1.',
					},
				},
			} )
		).toEqual( {
			fields: {
				log_404_retention_days:
					'log_404_retention_days must be at least 1.',
			},
			general: false,
		} );
	} );
	it( 'keeps unmapped params for the snackbar', () => {
		expect(
			splitSettingsErrors( {
				code: 'rest_invalid_param',
				data: {
					params: { log_404_max_rows: 'Too low.', evil: 'Nope.' },
				},
			} )
		).toEqual( {
			fields: { log_404_max_rows: 'Too low.' },
			general: true,
		} );
	} );
	it( 'treats every other error as general', () => {
		expect( splitSettingsErrors( { code: 'rest_forbidden' } ) ).toEqual( {
			fields: {},
			general: true,
		} );
		expect( splitSettingsErrors( undefined ) ).toEqual( {
			fields: {},
			general: true,
		} );
	} );
} );

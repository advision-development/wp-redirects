import {
	describedFieldProps,
	isStaleTest,
	joinIds,
} from '../../assets/src/utils/a11y';

describe( 'joinIds', () => {
	it( 'joins truthy ids with spaces', () => {
		expect( joinIds( 'a', false, 'b', null, undefined, '' ) ).toBe( 'a b' );
	} );

	it( 'returns undefined when nothing is left', () => {
		expect( joinIds( false, null ) ).toBeUndefined();
	} );
} );

describe( 'describedFieldProps', () => {
	it( 'leaves a valid field without help undescribed', () => {
		expect(
			describedFieldProps( {
				id: 'src',
				errorId: 'src-error',
				error: '',
			} )
		).toEqual( {
			id: 'src',
			'aria-invalid': undefined,
			'aria-describedby': undefined,
		} );
	} );

	it( 'points at the error and marks the field invalid', () => {
		expect(
			describedFieldProps( {
				id: 'src',
				errorId: 'src-error',
				error: 'Creates a loop',
			} )
		).toEqual( {
			id: 'src',
			'aria-invalid': true,
			'aria-describedby': 'src-error',
		} );
	} );

	it( 'keeps the help text linked alongside the error', () => {
		expect(
			describedFieldProps( {
				id: 'src',
				errorId: 'src-error',
				error: 'Bad pattern',
				hasHelp: true,
			} )[ 'aria-describedby' ]
		).toBe( 'src__help src-error' );
	} );
} );

describe( 'isStaleTest', () => {
	it( 'is not stale before any test ran', () => {
		expect( isStaleTest( null, '/a' ) ).toBe( false );
	} );

	it( 'is not stale while the input matches the tested path', () => {
		expect( isStaleTest( '/a', ' /a ' ) ).toBe( false );
	} );

	it( 'is stale once the input changes', () => {
		expect( isStaleTest( '/a', '/ab' ) ).toBe( true );
	} );
} );

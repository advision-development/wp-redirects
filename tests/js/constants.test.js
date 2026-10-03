import {
	errorMessage,
	fieldForError,
	isGone,
	ruleToPayload,
	statusTone,
} from '../../assets/src/constants';

describe( 'constants', () => {
	it( 'detects gone statuses', () => {
		expect( isGone( 410 ) ).toBe( true );
		expect( isGone( '451' ) ).toBe( true );
		expect( isGone( 301 ) ).toBe( false );
	} );
	it( 'maps status tones', () => {
		expect( statusTone( 308 ) ).toBe( 'permanent' );
		expect( statusTone( 307 ) ).toBe( 'temporary' );
		expect( statusTone( 410 ) ).toBe( 'gone' );
	} );
	it( 'maps errors to fields', () => {
		expect( fieldForError( { code: 'adv_redirects_duplicate' } ) ).toBe(
			'source'
		);
		expect( fieldForError( { code: 'adv_redirects_loop' } ) ).toBe(
			'target'
		);
		expect(
			fieldForError( {
				code: 'rest_invalid_param',
				data: { params: { status_code: 'bad' } },
			} )
		).toBe( 'status_code' );
		expect( fieldForError( { code: 'rest_forbidden' } ) ).toBe( 'form' );
		expect( fieldForError( undefined ) ).toBe( 'form' );
	} );
	it( 'builds readable error messages', () => {
		expect( errorMessage( { message: 'Nope' } ) ).toBe( 'Nope' );
		expect(
			errorMessage( {
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): source',
				data: { params: { source: 'source is too long.' } },
			} )
		).toBe( 'source is too long.' );
		expect( errorMessage( {} ) ).toBe(
			'Something went wrong. Please try again.'
		);
	} );
	it( 'strips read-only fields from payloads', () => {
		const payload = ruleToPayload( {
			id: 1,
			type: 'exact',
			source: '/a',
			target: '/b',
			status_code: 301,
			enabled: true,
			trailing_slash: false,
			note: '',
			hits: 4,
		} );
		expect( payload ).toStrictEqual( {
			type: 'exact',
			source: '/a',
			target: '/b',
			status_code: 301,
			enabled: true,
			trailing_slash: false,
			note: '',
		} );
	} );
	it( 'keeps the trailing-slash flag, so Undo re-creates the rule as it was', () => {
		const payload = ruleToPayload( {
			id: 7,
			type: 'regex',
			source: '^/forum/(.*)',
			target: '/forum/$1',
			status_code: 301,
			enabled: true,
			trailing_slash: true,
			note: '',
		} );
		expect( payload.trailing_slash ).toBe( true );
		expect( JSON.parse( JSON.stringify( payload ) ) ).toStrictEqual( {
			type: 'regex',
			source: '^/forum/(.*)',
			target: '/forum/$1',
			status_code: 301,
			enabled: true,
			trailing_slash: true,
			note: '',
		} );
	} );
	it( 'drops an undefined trailing-slash flag from the JSON body', () => {
		const payload = ruleToPayload( {
			type: 'exact',
			source: '/a',
			target: '/b',
			status_code: 301,
			enabled: true,
			note: '',
		} );
		expect(
			Object.keys( JSON.parse( JSON.stringify( payload ) ) )
		).not.toContain( 'trailing_slash' );
	} );
} );

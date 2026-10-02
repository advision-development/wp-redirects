import {
	initialState,
	redirectsReducer,
} from '../../assets/src/state/redirectsReducer';

const a = { id: 1, source: '/a', enabled: true, position: 0 };
const b = { id: 2, source: '/b', enabled: true, position: 1 };

describe( 'redirectsReducer', () => {
	it( 'loads and reports failures', () => {
		const loaded = redirectsReducer( initialState, {
			type: 'LOADED',
			items: [ a ],
		} );
		expect( loaded ).toEqual( {
			items: [ a ],
			loading: false,
			error: null,
		} );
		expect(
			redirectsReducer( initialState, {
				type: 'LOAD_FAILED',
				error: 'x',
			} ).error
		).toBe( 'x' );
	} );
	it( 'upserts, patches and removes', () => {
		let state = redirectsReducer( initialState, {
			type: 'LOADED',
			items: [ a ],
		} );
		state = redirectsReducer( state, { type: 'UPSERT', item: b } );
		state = redirectsReducer( state, {
			type: 'UPSERT',
			item: { ...a, source: '/a2' },
		} );
		expect( state.items.map( ( r ) => r.source ) ).toEqual( [
			'/a2',
			'/b',
		] );

		state = redirectsReducer( state, {
			type: 'PATCH',
			id: 2,
			patch: { enabled: false },
		} );
		expect( state.items[ 1 ].enabled ).toBe( false );

		state = redirectsReducer( state, {
			type: 'PATCH_MANY',
			ids: [ 1, 2 ],
			patch: { enabled: true },
		} );
		expect( state.items.every( ( r ) => r.enabled ) ).toBe( true );

		state = redirectsReducer( state, { type: 'REMOVE', ids: [ 1 ] } );
		expect( state.items.map( ( r ) => r.id ) ).toEqual( [ 2 ] );
	} );
	it( 'reorders positions', () => {
		const state = redirectsReducer(
			{ ...initialState, items: [ a, b ] },
			{ type: 'REORDER', ids: [ 2, 1 ] }
		);
		expect( state.items.find( ( r ) => r.id === 2 ).position ).toBe( 1 );
		expect( state.items.find( ( r ) => r.id === 1 ).position ).toBe( 2 );
	} );
	it( 'ignores unknown actions', () => {
		expect( redirectsReducer( initialState, { type: 'NOPE' } ) ).toBe(
			initialState
		);
	} );
} );

import {
	createdLine,
	editedLine,
	VIA_LABELS,
} from '../../assets/src/utils/attribution';

// An injected formatter keeps these tests independent of the time zone.
const format = ( value ) => `<${ value }>`;

const rule = {
	created_by_name: 'ivan.morales',
	updated_by_name: null,
	created_via: 'manual',
	created_at: '2026-10-02 15:03:00',
	updated_at: '2026-10-02 15:03:00',
};

describe( 'VIA_LABELS', () => {
	it( 'labels every way except manual', () => {
		expect( VIA_LABELS.manual ).toBeUndefined();
		expect( VIA_LABELS.import ).toBe( 'Imported' );
		expect( VIA_LABELS.slug ).toBe( 'Slug change' );
		expect( VIA_LABELS.api ).toBe( 'API' );
	} );
} );

describe( 'createdLine', () => {
	it( 'names the creator and the date', () => {
		expect( createdLine( rule, format ) ).toBe(
			'By: ivan.morales · <2026-10-02 15:03:00>'
		);
	} );
	it( 'falls back to the date alone for rules without a creator', () => {
		expect(
			createdLine( { ...rule, created_by_name: null }, format )
		).toBe( 'Added <2026-10-02 15:03:00>' );
		expect(
			createdLine( { ...rule, created_by_name: undefined }, format )
		).toBe( 'Added <2026-10-02 15:03:00>' );
	} );
	it( 'reads the same whichever way the rule was created', () => {
		[ 'import', 'slug', 'api' ].forEach( ( via ) => {
			expect( createdLine( { ...rule, created_via: via }, format ) ).toBe(
				'By: ivan.morales · <2026-10-02 15:03:00>'
			);
		} );
	} );
	it( 'uses the date formatter that ships with the admin by default', () => {
		expect( createdLine( rule ) ).toMatch(
			/^By: ivan\.morales · [A-Z][a-z]{2} \d{1,2}, 2026 /
		);
	} );
} );

describe( 'editedLine', () => {
	it( 'is empty when the rule was never edited', () => {
		expect(
			editedLine( { ...rule, updated_by_name: 'sam' }, format )
		).toBe( '' );
	} );
	it( 'is empty when the editor is unknown', () => {
		expect(
			editedLine( { ...rule, updated_at: '2026-10-03 09:00:00' }, format )
		).toBe( '' );
	} );
	it( 'names the editor and the date once edited', () => {
		expect(
			editedLine(
				{
					...rule,
					updated_by_name: 'sam',
					updated_at: '2026-10-03 09:00:00',
				},
				format
			)
		).toBe( 'Last edited by sam on <2026-10-03 09:00:00>' );
	} );
} );

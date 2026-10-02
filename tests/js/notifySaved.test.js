import { notifySaved } from '../../assets/src/utils/notifySaved';

describe( 'notifySaved', () => {
	it( 'shows a plain message when there is no chain', () => {
		const notify = jest.fn();
		notifySaved(
			{ rule: { id: 1 }, warnings: [] },
			{ notify, onUpdate: jest.fn(), message: 'Saved.' }
		);
		expect( notify ).toHaveBeenCalledWith( { message: 'Saved.' } );
	} );
	it( 'offers to point directly to the final hop', async () => {
		const notify = jest.fn();
		const onUpdate = jest.fn().mockResolvedValue( {} );
		notifySaved(
			{
				rule: { id: 7 },
				warnings: [
					{ code: 'chain', hops: [ '/a', '/b', '/c' ], final: '/c' },
				],
			},
			{ notify, onUpdate, message: 'Saved.' }
		);
		const notice = notify.mock.calls[ 0 ][ 0 ];
		expect( notice.message ).toContain( '/a → /b → /c' );
		expect( notice.actions[ 0 ].label ).toBe( 'Point directly to /c' );
		notice.actions[ 0 ].onClick();
		expect( onUpdate ).toHaveBeenCalledWith( 7, { target: '/c' } );
	} );
} );

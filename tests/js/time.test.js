import { parseGmt, timeAgo } from '../../assets/src/utils/time';

const now = new Date( '2026-10-02T12:00:00Z' );

describe( 'timeAgo', () => {
	it( 'handles empty values', () => {
		expect( timeAgo( null, now ) ).toBe( 'Never' );
		expect( parseGmt( 'garbage' ) ).toBeNull();
	} );
	it( 'formats relative times from GMT MySQL datetimes', () => {
		expect( timeAgo( '2026-10-02 11:59:30', now ) ).toBe( 'Just now' );
		expect( timeAgo( '2026-10-02 11:55:00', now ) ).toBe( '5 minutes ago' );
		expect( timeAgo( '2026-10-02 11:00:00', now ) ).toBe( '1 hour ago' );
		expect( timeAgo( '2026-09-30 12:00:00', now ) ).toBe( '2 days ago' );
	} );
} );

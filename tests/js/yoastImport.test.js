import fixture from '../fixtures/yoast-redirects-sample.json';
import {
	batchPayload,
	entrySource,
	hideNotice,
	NOTICE_KEY,
	noticeHidden,
	REMOVE_CHUNK,
	removalCandidates,
	serverModeWarning,
	yoastPayload,
} from '../../assets/src/utils/yoastImport';
import {
	buildReport,
	MAX_PREVIEW,
	NOTE_LABELS,
} from '../../assets/src/utils/redirectionImport';

const entries = fixture.map( ( entry, index ) => ( {
	id: index + 1,
	...entry,
} ) );

describe( 'payloads', () => {
	it( 'builds the yoast preview payload from the entries as given', () => {
		expect( yoastPayload( entries ) ).toEqual( {
			source: 'yoast',
			redirects: entries,
		} );
	} );

	it( 'sends groups only for Redirection batches', () => {
		const batch = entries.slice( 0, 2 );
		expect( batchPayload( yoastPayload( entries ), batch ) ).toEqual( {
			source: 'yoast',
			redirects: batch,
		} );
		expect(
			batchPayload(
				{ source: 'redirection', groups: [ { id: 1 } ], redirects: [] },
				batch
			)
		).toEqual( {
			source: 'redirection',
			groups: [ { id: 1 } ],
			redirects: batch,
		} );
	} );

	it( 'reads the source of either kind of entry', () => {
		expect( entrySource( entries[ 0 ] ) ).toBe( 'fy-old-page' );
		expect( entrySource( { url: '/a/' } ) ).toBe( '/a/' );
		expect( entrySource( undefined ) ).toBe( '' );
	} );
} );

describe( 'removalCandidates', () => {
	const preview = {
		entries: [
			{ index: 0, status: 'new' },
			{ index: 1, status: 'new' },
			{ index: 2, status: 'superseded' },
			{ index: 13, status: 'skipped' },
			{ index: 24, status: 'new' },
		],
	};

	it( 'takes imported entries and superseded ones, never skipped ones', () => {
		expect( removalCandidates( preview, entries, [ 0, 24 ] ) ).toEqual( [
			{ origin: 'fy-old-page', format: 'plain' },
			{ origin: 'fy-case-page', format: 'plain' },
			{ origin: 'fy-numeric-type', format: 'plain' },
		] );
	} );

	it( 'leaves out entries the import did not confirm', () => {
		expect( removalCandidates( preview, entries, [] ) ).toEqual( [
			{ origin: 'fy-case-page', format: 'plain' },
		] );
	} );
} );

describe( 'notice storage', () => {
	afterEach( () => {
		jest.restoreAllMocks();
		window.localStorage.clear();
	} );

	it( 'remembers "Not now"', () => {
		expect( noticeHidden() ).toBe( false );
		hideNotice();
		expect( window.localStorage.getItem( NOTICE_KEY ) ).toBe( '1' );
		expect( noticeHidden() ).toBe( true );
	} );

	it( 'noticeHidden and hideNotice survive a throwing localStorage', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		expect( noticeHidden() ).toBe( false );
		expect( () => hideNotice() ).not.toThrow();
	} );
} );

describe( 'serverModeWarning', () => {
	it( 'is empty for PHP redirects and missing status', () => {
		expect( serverModeWarning( null ) ).toBe( '' );
		expect(
			serverModeWarning( { server_mode: 'php', premium_active: true } )
		).toBe( '' );
		expect(
			serverModeWarning( { server_mode: 'none', premium_active: false } )
		).toBe( '' );
	} );

	it( 'warns about server files, differently with and without Premium', () => {
		const active = serverModeWarning( {
			server_mode: 'nginx',
			premium_active: true,
		} );
		const inactive = serverModeWarning( {
			server_mode: 'htaccess',
			premium_active: false,
		} );
		expect( active ).toMatch( /reload/ );
		expect( inactive ).toMatch( /does not edit server files/ );
	} );
} );

describe( 'shared import changes', () => {
	it( 'raises the preview limit and labels the new note', () => {
		expect( MAX_PREVIEW ).toBe( 5000 );
		expect( REMOVE_CHUNK ).toBe( 500 );
		expect( NOTE_LABELS.case_sensitive_source ).toMatch( /case/i );
	} );

	it( 'names the source plugin in the report', () => {
		const report = buildReport( {
			summary: { plugin: 'yoast', version: '27.3', date: '' },
			preview: { entries: [] },
		} );
		expect( report.file ).toEqual( {
			plugin: 'yoast',
			version: '27.3',
			date: '',
		} );
	} );
} );

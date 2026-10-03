import fixture from '../fixtures/yoast-redirects-sample.json';
import {
	batchPayload,
	entrySource,
	hideNotice,
	NOTICE_KEY,
	noticeHidden,
	pendingRemovals,
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
			{ index: 0, status: 'new', notes: [] },
			{ index: 1, status: 'new', notes: [] },
			{ index: 2, status: 'superseded', notes: [] },
			{ index: 5, status: 'new', notes: [] },
			{ index: 13, status: 'skipped', notes: [] },
			{
				index: 17,
				status: 'new',
				notes: [ 'case_sensitive_source', 'regex_query' ],
			},
			{ index: 24, status: 'new' },
		],
	};

	it( 'takes imported entries and superseded ones, never skipped ones', () => {
		expect( removalCandidates( preview, entries, [ 0, 5, 24 ] ) ).toEqual( [
			{
				origin: 'fy-old-page',
				format: 'plain',
				url: 'fy-new-page',
				type: 301,
			},
			{
				origin: 'fy-case-page',
				format: 'plain',
				url: 'fy-other',
				type: 302,
			},
			{ origin: 'fy-gone', format: 'plain', url: '', type: 410 },
			{
				origin: 'fy-numeric-type',
				format: 'plain',
				url: 'fy-n',
				type: 301,
			},
		] );
	} );

	it( 'leaves out entries the import did not confirm', () => {
		expect( removalCandidates( preview, entries, [] ) ).toEqual( [
			{
				origin: 'fy-case-page',
				format: 'plain',
				url: 'fy-other',
				type: 302,
			},
		] );
	} );

	it( 'keeps a regex that matched the query string in Yoast', () => {
		const origins = removalCandidates( preview, entries, [ 17 ] ).map(
			( item ) => item.origin
		);
		expect( entries[ 17 ].origin ).toBe( '^/fy-search\\?q=(.*)' );
		expect( origins ).not.toContain( entries[ 17 ].origin );
	} );
} );

describe( 'pendingRemovals', () => {
	const candidates = [
		{ origin: 'a', format: 'plain', url: 'x', type: 301 },
		{ origin: 'a', format: 'regex', url: 'y', type: 301 },
		{ origin: 'b', format: 'plain', url: '', type: 410 },
		{ origin: 'c', format: 'plain', url: 'z', type: 302 },
	];

	it( 'sends everything the first time', () => {
		expect( pendingRemovals( candidates, [] ) ).toEqual( candidates );
	} );

	it( 'skips only the items already reported removed', () => {
		const reported = [
			{ origin: 'a', format: 'plain', result: 'removed' },
			{ origin: 'b', format: 'plain', result: 'not_covered' },
			{ origin: 'c', format: 'plain', result: 'not_found' },
		];
		expect( pendingRemovals( candidates, reported ) ).toEqual(
			candidates.slice( 1 )
		);
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
		expect( inactive ).toMatch( /^Yoast’s redirects/ );
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

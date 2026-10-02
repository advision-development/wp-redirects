import fixture from '../fixtures/redirection-export-sample.json';
import {
	buildReport,
	checkRedirectionExport,
	chunk,
	groupPreview,
	importableEntries,
	stripExport,
} from '../../assets/src/utils/redirectionImport';

const clone = () => JSON.parse( JSON.stringify( fixture ) );

describe( 'checkRedirectionExport', () => {
	it( 'accepts a Redirection export and summarizes it', () => {
		expect( checkRedirectionExport( clone() ) ).toEqual( {
			ok: true,
			errors: [],
			summary: {
				version: '5.10.1',
				date: 'Fri, 02 Oct 2026 12:00:00 +0000',
				total: 21,
				exact: 18,
				regex: 3,
				logs: 2,
				errors404: 2,
			},
		} );
	} );

	it( 'rejects files that are not Redirection exports', () => {
		expect( checkRedirectionExport( null ).ok ).toBe( false );
		expect(
			checkRedirectionExport( { redirects: [] } ).errors[ 0 ]
		).toMatch( /plugin\.version/ );
		const noList = clone();
		delete noList.redirects;
		expect( checkRedirectionExport( noList ).errors[ 0 ] ).toMatch(
			/redirects/
		);
		const empty = clone();
		empty.redirects = [];
		expect( checkRedirectionExport( empty ).ok ).toBe( false );
	} );

	it( 'reports wrong entry fields with the entry number', () => {
		const data = clone();
		data.redirects[ 1 ].regex = 'yes';
		expect( checkRedirectionExport( data ).errors ).toEqual( [
			'Entry 2: "regex" must be true or false.',
		] );
	} );

	it( 'caps the error list at five', () => {
		const data = clone();
		data.redirects.forEach( ( entry ) => {
			delete entry.url;
		} );
		expect( checkRedirectionExport( data ).errors ).toHaveLength( 5 );
	} );

	it( 'accepts numeric strings for action_code and group_id', () => {
		const data = clone();
		data.redirects[ 0 ].action_code = '301';
		data.redirects[ 0 ].group_id = '1';
		expect( checkRedirectionExport( data ).ok ).toBe( true );
	} );

	it( 'accepts null action_data (error actions such as 410)', () => {
		const data = clone();
		data.redirects[ 10 ].action_data = null;
		expect( checkRedirectionExport( data ).ok ).toBe( true );
	} );

	it( 'validates groups when present', () => {
		const data = clone();
		data.groups = [ { id: 1 } ];
		expect( checkRedirectionExport( data ).ok ).toBe( false );
	} );
} );

describe( 'stripExport', () => {
	it( 'removes logs, 404s, hits and last access', () => {
		const payload = stripExport( clone() );
		expect( Object.keys( payload ).sort() ).toEqual( [
			'groups',
			'redirects',
			'source',
			'version',
		] );
		expect( payload.source ).toBe( 'redirection' );
		expect( payload.groups ).toEqual( [
			{ id: 1, name: 'Redirections' },
			{ id: 2, name: 'Modified Posts' },
		] );
		const json = JSON.stringify( payload );
		expect( json ).not.toMatch(
			/192\.0\.2|FixtureAgent|last_access|"hits"/
		);
		expect( Object.keys( payload.redirects[ 0 ] ).sort() ).toEqual( [
			'action_code',
			'action_data',
			'action_type',
			'enabled',
			'group_id',
			'id',
			'match_data',
			'match_type',
			'position',
			'regex',
			'title',
			'url',
		] );
	} );

	it( 'keeps entries ordered by position, then original order', () => {
		const data = clone();
		data.redirects[ 0 ].position = 99;
		data.redirects[ 1 ].position = 3;
		const ids = stripExport( data ).redirects.map( ( entry ) => entry.id );
		expect( ids.slice( 0, 4 ) ).toEqual( [ 3, 2, 4, 5 ] );
		expect( ids[ ids.length - 1 ] ).toBe( 1 );
	} );
} );

describe( 'batching helpers', () => {
	const preview = {
		entries: [
			{ index: 0, status: 'new', warnings: [] },
			{ index: 1, status: 'overwrite', warnings: [ { code: 'chain' } ] },
			{
				index: 2,
				status: 'skipped',
				warnings: [],
				error: { code: 'x', message: 'Nope' },
				source: '/c',
			},
			{ index: 3, status: 'superseded', warnings: [], source: '/d' },
		],
	};
	const redirects = [ { id: 10 }, { id: 11 }, { id: 12 }, { id: 13 } ];

	it( 'chunks arrays', () => {
		expect( chunk( [ 1, 2, 3, 4, 5 ], 2 ) ).toEqual( [
			[ 1, 2 ],
			[ 3, 4 ],
			[ 5 ],
		] );
		expect( chunk( [], 50 ) ).toEqual( [] );
	} );

	it( 'selects only new and overwrite entries', () => {
		expect( importableEntries( preview, redirects ) ).toEqual( [
			{ index: 0, entry: { id: 10 } },
			{ index: 1, entry: { id: 11 } },
		] );
	} );

	it( 'groups preview entries', () => {
		const groups = groupPreview( preview );
		expect( groups.new ).toHaveLength( 1 );
		expect( groups.overwrite ).toHaveLength( 1 );
		expect( groups.warnings.map( ( entry ) => entry.index ) ).toEqual( [
			1,
		] );
		expect( groups.skipped ).toHaveLength( 1 );
		expect( groups.superseded ).toHaveLength( 1 );
	} );

	it( 'builds a report of skipped and superseded entries', () => {
		const report = buildReport( {
			summary: { version: '5.10.1', date: 'd' },
			preview,
			importSkipped: [
				{
					index: 0,
					source: '/a',
					error: { code: 'db', message: 'Failed' },
				},
			],
		} );
		expect( report.file ).toEqual( {
			plugin: 'redirection',
			version: '5.10.1',
			date: 'd',
		} );
		expect( report.skipped ).toEqual( [
			{
				index: 2,
				source: '/c',
				code: 'x',
				message: 'Nope',
				stage: 'preview',
			},
			{
				index: 0,
				source: '/a',
				code: 'db',
				message: 'Failed',
				stage: 'import',
			},
		] );
		expect( report.superseded ).toEqual( [ { index: 3, source: '/d' } ] );
	} );
} );

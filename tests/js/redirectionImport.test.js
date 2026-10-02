import fixture from '../fixtures/redirection-export-sample.json';
import {
	buildReport,
	checkRedirectionExport,
	chunk,
	groupPreview,
	importableEntries,
	importErrorMessage,
	isTooLarge,
	NOTE_LABELS,
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
			{ id: 1, name: 'Redirections', status: 'enabled' },
			{ id: 2, name: 'Modified Posts', status: 'enabled' },
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

	it( 'keeps the status of a disabled group and drops other group fields', () => {
		const data = clone();
		data.groups = [
			{ id: 1, name: 'A', status: 'disabled', module_id: 1 },
			{ id: 2, name: 'B', enabled: false },
			{ id: 3, name: 'C', status: 5, enabled: 'no' },
		];
		expect( stripExport( data ).groups ).toEqual( [
			{ id: 1, name: 'A', status: 'disabled' },
			{ id: 2, name: 'B', enabled: false },
			{ id: 3, name: 'C' },
		] );
	} );

	it( 'never sends condition data from non-redirect entries', () => {
		const data = clone();
		const secret = {
			ip: [ '203.0.113.7', '198.51.100.0/24' ],
			agent: 'SecretAgent/9',
			cookie: 'session=abc123',
			header: 'x-private: yes',
			url: '/fx-keep-me/',
			url_from: '/fx-from/',
		};
		const login = {
			...data.redirects[ 12 ],
			action_data: {
				logged_in: '/fx-in/',
				logged_out: '/fx-out/',
				ip: secret.ip,
			},
		};
		const agent = {
			...data.redirects[ 12 ],
			match_type: 'agent',
			action_data: { agent: secret.agent, url_from: secret.url_from },
			match_data: {
				source: { flag_case: true, flag_regex: false },
				extra: secret.cookie,
			},
		};
		const ip = {
			...data.redirects[ 12 ],
			match_type: 'ip',
			action_data: { ip: secret.ip, header: secret.header },
		};
		data.redirects = [ login, agent, ip ];

		const payload = stripExport( data );
		payload.redirects.forEach( ( entry ) => {
			expect( entry.action_data ).toBeNull();
		} );
		const json = JSON.stringify( payload );
		expect( json ).not.toMatch(
			/203\.0\.113|198\.51\.100|SecretAgent|session=abc123|x-private|fx-from|fx-in|fx-out/
		);
	} );

	it( 'sends only the target url of a redirect and only the source flags of match_data', () => {
		const data = clone();
		data.redirects[ 0 ].action_data = {
			url: '/fx-new-page/',
			ip: [ '203.0.113.9' ],
		};
		data.redirects[ 0 ].match_data = {
			source: {
				flag_case: false,
				flag_query: 'ignore',
				flag_regex: false,
				flag_trailing: true,
				other: 'x',
			},
			options: { log_exclude: true },
		};
		const first = stripExport( data ).redirects.find(
			( entry ) => entry.id === 1
		);
		expect( first.action_data ).toEqual( { url: '/fx-new-page/' } );
		expect( first.match_data ).toEqual( {
			source: {
				flag_case: false,
				flag_query: 'ignore',
				flag_regex: false,
				flag_trailing: true,
			},
		} );
	} );

	it( 'copies only the match flags that are present', () => {
		const data = clone();
		data.redirects[ 0 ].match_data = { source: { flag_case: false } };
		data.redirects[ 1 ].match_data = null;
		const redirects = stripExport( data ).redirects;
		expect(
			redirects.find( ( entry ) => entry.id === 1 ).match_data
		).toEqual( { source: { flag_case: false } } );
		expect(
			redirects.find( ( entry ) => entry.id === 2 ).match_data
		).toEqual( { source: {} } );
	} );

	it( 'sends null action_data for error actions', () => {
		const gone = stripExport( clone() ).redirects.find(
			( entry ) => entry.id === 11
		);
		expect( gone.action_data ).toBeNull();
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

describe( 'upload size errors', () => {
	const TOO_LARGE =
		'The server rejected the upload as too large. Split the export into smaller files and import them one at a time.';

	it( 'recognises a refused body', () => {
		expect( isTooLarge( { status: 413 } ) ).toBe( true );
		expect( isTooLarge( { data: { status: 413 } } ) ).toBe( true );
		expect( isTooLarge( { code: 'rest_request_too_large' } ) ).toBe( true );
		expect( isTooLarge( { code: 'fetch_error' } ) ).toBe( true );
		expect( isTooLarge( new TypeError( 'Failed to fetch' ) ) ).toBe( true );
		expect( isTooLarge( { code: 'rest_forbidden' } ) ).toBe( false );
		expect( isTooLarge( null ) ).toBe( false );
	} );

	it( 'maps them to the split-the-file message, others to their own', () => {
		expect( importErrorMessage( { status: 413 } ) ).toBe( TOO_LARGE );
		expect(
			importErrorMessage( { code: 'x', message: 'Not allowed.' } )
		).toBe( 'Not allowed.' );
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
			{
				index: 3,
				status: 'superseded',
				warnings: [],
				source: '/d',
				superseded_by: 12,
				error: { code: 'superseded', message: 'Used entry #12.' },
			},
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

	it( 'labels the disabled-group note', () => {
		expect( NOTE_LABELS.group_disabled ).toBe(
			'Its Redirection group is disabled, so it is imported disabled.'
		);
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
		expect( report.superseded ).toEqual( [
			{
				index: 3,
				source: '/d',
				superseded_by: 12,
				message: 'Used entry #12.',
			},
		] );
	} );
} );

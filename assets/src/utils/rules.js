export const DEFAULT_FILTERS = {
	search: '',
	status: 'all',
	enabled: 'all',
	origin: 'all',
};

export const isFiltered = ( filters ) =>
	filters.search.trim() !== '' ||
	filters.status !== 'all' ||
	filters.enabled !== 'all' ||
	filters.origin !== 'all';

function matchesSearch( rule, search ) {
	const query = search.trim().toLowerCase();
	if ( ! query ) {
		return true;
	}
	return [ rule.source, rule.target || '', rule.note || '' ].some(
		( value ) => value.toLowerCase().includes( query )
	);
}

export function filterRules( rules, filters = DEFAULT_FILTERS ) {
	const {
		search = '',
		status = 'all',
		enabled = 'all',
		origin = 'all',
	} = filters;
	return rules.filter(
		( rule ) =>
			matchesSearch( rule, search ) &&
			( status === 'all' || rule.status_code === Number( status ) ) &&
			( enabled === 'all' ||
				rule.enabled === ( enabled === 'enabled' ) ) &&
			( origin === 'all' || rule.origin === origin )
	);
}

const SORT_KEYS = {
	source: ( rule ) => rule.source.toLowerCase(),
	target: ( rule ) => ( rule.target || '' ).toLowerCase(),
	status_code: ( rule ) => rule.status_code,
	hits: ( rule ) => rule.hits,
	last_hit_at: ( rule ) => rule.last_hit_at || '',
	created_at: ( rule ) => rule.created_at,
};

export function sortRules( rules, { orderby = 'source', order = 'asc' } = {} ) {
	const key = SORT_KEYS[ orderby ] || SORT_KEYS.source;
	const direction = order === 'desc' ? -1 : 1;
	return [ ...rules ].sort( ( a, b ) => {
		const x = key( a );
		const y = key( b );
		if ( x < y ) {
			return -direction;
		}
		if ( x > y ) {
			return direction;
		}
		return a.id - b.id;
	} );
}

export function splitByType( rules ) {
	return {
		exact: rules.filter( ( rule ) => rule.type === 'exact' ),
		regex: rules
			.filter( ( rule ) => rule.type === 'regex' )
			.sort( ( a, b ) => a.position - b.position || a.id - b.id ),
	};
}

export function moveItem( ids, from, to ) {
	const next = [ ...ids ];
	if ( to < 0 || to >= next.length || from < 0 || from >= next.length ) {
		return next;
	}
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}

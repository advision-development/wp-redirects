export const PAGE_SIZES = [ 25, 50, 100, 0 ];
export const DEFAULT_PAGE_SIZE = 25;

const STORAGE_KEY = 'adv_redirects_page_size';

/**
 * One page of a list.
 *
 * @param {Array}  items    Full list.
 * @param {number} page     0-based page; clamped into range.
 * @param {number} pageSize Rows per page; 0 shows everything on one page.
 * @return {{items: Array, page: number, pageCount: number, start: number, end: number, total: number}} The page's rows and its 0-based slice bounds.
 */
export function paginate( items, page, pageSize ) {
	const total = items.length;
	const pageCount =
		pageSize > 0 ? Math.max( 1, Math.ceil( total / pageSize ) ) : 1;
	const wanted = Number.isFinite( page ) ? Math.floor( page ) : 0;
	const current = Math.min( Math.max( wanted, 0 ), pageCount - 1 );
	const start = pageSize > 0 ? current * pageSize : 0;
	const end = pageSize > 0 ? Math.min( start + pageSize, total ) : total;
	return {
		items: items.slice( start, end ),
		page: current,
		pageCount,
		start,
		end,
		total,
	};
}

/**
 * The page that holds a list index.
 *
 * @param {number} index    0-based index in the full list.
 * @param {number} pageSize Rows per page; 0 means one page.
 * @return {number} 0-based page.
 */
export function pageOfIndex( index, pageSize ) {
	return pageSize > 0 ? Math.floor( Math.max( index, 0 ) / pageSize ) : 0;
}

export function readPageSize() {
	try {
		const stored = window.localStorage.getItem( STORAGE_KEY );
		// Null or empty must not read as 0, which means "All".
		if ( stored === null || stored.trim() === '' ) {
			return DEFAULT_PAGE_SIZE;
		}
		const size = Number( stored );
		return PAGE_SIZES.includes( size ) ? size : DEFAULT_PAGE_SIZE;
	} catch {
		return DEFAULT_PAGE_SIZE;
	}
}

export function writePageSize( size ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, String( size ) );
	} catch {
		// Storage can be blocked or full; the choice then lasts for this visit only.
	}
}

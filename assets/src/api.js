import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const NS = '/adv-redirects/v1';

export const api = {
	listRedirects: () => apiFetch( { path: `${ NS }/redirects` } ),
	createRedirect: ( data ) =>
		apiFetch( { path: `${ NS }/redirects`, method: 'POST', data } ),
	updateRedirect: ( id, data ) =>
		apiFetch( { path: `${ NS }/redirects/${ id }`, method: 'PUT', data } ),
	deleteRedirect: ( id ) =>
		apiFetch( { path: `${ NS }/redirects/${ id }`, method: 'DELETE' } ),
	bulkRedirects: ( action, ids ) =>
		apiFetch( {
			path: `${ NS }/redirects/bulk`,
			method: 'POST',
			data: { action, ids },
		} ),
	reorderRedirects: ( ids ) =>
		apiFetch( {
			path: `${ NS }/redirects/reorder`,
			method: 'POST',
			data: { ids },
		} ),
	testUrl: ( path ) =>
		apiFetch( { path: `${ NS }/test`, method: 'POST', data: { path } } ),
	async list404s( { page, perPage, search, orderby, order } ) {
		const response = await apiFetch( {
			path: addQueryArgs( `${ NS }/404s`, {
				page,
				per_page: perPage,
				search,
				orderby,
				order,
			} ),
			parse: false,
		} );
		return {
			items: await response.json(),
			total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
			pages: Number( response.headers.get( 'X-WP-TotalPages' ) || 0 ),
		};
	},
	delete404: ( id ) =>
		apiFetch( { path: `${ NS }/404s/${ id }`, method: 'DELETE' } ),
	bulkDelete404s: ( ids ) =>
		apiFetch( {
			path: `${ NS }/404s/bulk`,
			method: 'POST',
			data: { action: 'delete', ids },
		} ),
	clear404s: () => apiFetch( { path: `${ NS }/404s`, method: 'DELETE' } ),
	getSettings: () => apiFetch( { path: `${ NS }/settings` } ),
	saveSettings: ( data ) =>
		apiFetch( { path: `${ NS }/settings`, method: 'PUT', data } ),
	importPreview: ( payload ) =>
		apiFetch( {
			path: `${ NS }/import/preview`,
			method: 'POST',
			data: payload,
		} ),
	importBatch: ( payload ) =>
		apiFetch( { path: `${ NS }/import`, method: 'POST', data: payload } ),
	yoastStatus: () => apiFetch( { path: `${ NS }/import/yoast` } ),
	yoastRemove: ( entries ) =>
		apiFetch( {
			path: `${ NS }/import/yoast/remove`,
			method: 'POST',
			data: { entries },
		} ),
	yoastRestore: () =>
		apiFetch( {
			path: `${ NS }/import/yoast/restore`,
			method: 'POST',
			data: {},
		} ),
	yoastDeleteBackup: () =>
		apiFetch( { path: `${ NS }/import/yoast/backup`, method: 'DELETE' } ),
};

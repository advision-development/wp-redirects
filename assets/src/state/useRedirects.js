import { useCallback, useEffect, useReducer, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage, ruleToPayload } from '../constants';
import { restoredRegexOrder } from '../utils/rules';
import { initialState, redirectsReducer } from './redirectsReducer';

export function useRedirects( notify ) {
	const [ state, dispatch ] = useReducer( redirectsReducer, initialState );
	const itemsRef = useRef( state.items );
	itemsRef.current = state.items;

	const reload = useCallback( async () => {
		try {
			dispatch( { type: 'LOADED', items: await api.listRedirects() } );
		} catch ( error ) {
			dispatch( { type: 'LOAD_FAILED', error: errorMessage( error ) } );
		}
	}, [] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	const create = useCallback(
		async ( data ) => {
			const result = await api.createRedirect( data );
			dispatch( { type: 'UPSERT', item: result.rule } );
			reload();
			return result;
		},
		[ reload ]
	);

	const update = useCallback(
		async ( id, patch ) => {
			// Captured before the optimistic patch so a failed save can roll back.
			// eslint-disable-next-line @wordpress/no-unused-vars-before-return
			const previous = itemsRef.current.find(
				( rule ) => rule.id === id
			);
			dispatch( { type: 'PATCH', id, patch } );
			try {
				const result = await api.updateRedirect( id, patch );
				dispatch( { type: 'UPSERT', item: result.rule } );
				reload();
				return result;
			} catch ( error ) {
				if ( previous ) {
					dispatch( { type: 'UPSERT', item: previous } );
				}
				throw error;
			}
		},
		[ reload ]
	);

	const remove = useCallback(
		async ( rule ) => {
			// The list before the delete, to put a re-created regex rule back in place.
			const before = itemsRef.current;
			dispatch( { type: 'REMOVE', ids: [ rule.id ] } );
			try {
				await api.deleteRedirect( rule.id );
				reload();
				notify( {
					message: __( 'Redirect deleted.', 'wp-redirects' ),
					actions: [
						{
							label: __( 'Undo', 'wp-redirects' ),
							onClick: async () => {
								try {
									const result = await create(
										ruleToPayload( rule )
									);
									// Re-creating appends the rule, so restore a regex rule's order.
									const order = restoredRegexOrder(
										before,
										rule,
										result.rule.id
									);
									if ( order ) {
										await api.reorderRedirects( order );
										await reload();
									}
								} catch ( error ) {
									notify( {
										status: 'error',
										message: errorMessage( error ),
									} );
								}
							},
						},
					],
				} );
				return true;
			} catch ( error ) {
				dispatch( { type: 'UPSERT', item: rule } );
				notify( { status: 'error', message: errorMessage( error ) } );
				return false;
			}
		},
		[ create, notify, reload ]
	);

	const bulk = useCallback(
		async ( action, ids ) => {
			const previous = itemsRef.current;
			if ( action === 'delete' ) {
				dispatch( { type: 'REMOVE', ids } );
			} else {
				dispatch( {
					type: 'PATCH_MANY',
					ids,
					patch: { enabled: action === 'enable' },
				} );
			}
			try {
				const result = await api.bulkRedirects( action, ids );
				await reload();
				return result;
			} catch ( error ) {
				dispatch( { type: 'LOADED', items: previous } );
				throw error;
			}
		},
		[ reload ]
	);

	const reorder = useCallback(
		async ( ids ) => {
			const previous = itemsRef.current;
			dispatch( { type: 'REORDER', ids } );
			try {
				await api.reorderRedirects( ids );
				// Chain and loop flags depend on the order.
				reload();
			} catch ( error ) {
				dispatch( { type: 'LOADED', items: previous } );
				notify( { status: 'error', message: errorMessage( error ) } );
			}
		},
		[ notify, reload ]
	);

	return { ...state, reload, create, update, remove, bulk, reorder };
}

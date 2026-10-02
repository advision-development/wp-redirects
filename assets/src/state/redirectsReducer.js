export const initialState = { items: [], loading: true, error: null };

export function redirectsReducer( state, action ) {
	switch ( action.type ) {
		case 'LOADED':
			return { items: action.items, loading: false, error: null };
		case 'LOAD_FAILED':
			return { ...state, loading: false, error: action.error };
		case 'UPSERT': {
			const exists = state.items.some(
				( rule ) => rule.id === action.item.id
			);
			return {
				...state,
				items: exists
					? state.items.map( ( rule ) =>
							rule.id === action.item.id ? action.item : rule
						)
					: [ ...state.items, action.item ],
			};
		}
		case 'PATCH':
			return {
				...state,
				items: state.items.map( ( rule ) =>
					rule.id === action.id ? { ...rule, ...action.patch } : rule
				),
			};
		case 'PATCH_MANY':
			return {
				...state,
				items: state.items.map( ( rule ) =>
					action.ids.includes( rule.id )
						? { ...rule, ...action.patch }
						: rule
				),
			};
		case 'REMOVE':
			return {
				...state,
				items: state.items.filter(
					( rule ) => ! action.ids.includes( rule.id )
				),
			};
		case 'REORDER': {
			const positions = new Map(
				action.ids.map( ( id, index ) => [ id, index + 1 ] )
			);
			return {
				...state,
				items: state.items.map( ( rule ) =>
					positions.has( rule.id )
						? { ...rule, position: positions.get( rule.id ) }
						: rule
				),
			};
		}
		default:
			return state;
	}
}

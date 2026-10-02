import { useCallback, useState } from '@wordpress/element';

let nextId = 1;

export function useNotices() {
	const [ notices, setNotices ] = useState( [] );

	const dismiss = useCallback(
		( id ) =>
			setNotices( ( list ) =>
				list.filter( ( notice ) => notice.id !== id )
			),
		[]
	);

	const notify = useCallback(
		( { message, status = 'success', actions = [] } ) => {
			const id = `adv-redirects-notice-${ nextId++ }`;
			setNotices( ( list ) => [
				...list.slice( -2 ),
				{
					id,
					content: message,
					spokenMessage: message,
					status,
					actions,
					explicitDismiss: status === 'error',
				},
			] );
			return id;
		},
		[]
	);

	return { notices, notify, dismiss };
}

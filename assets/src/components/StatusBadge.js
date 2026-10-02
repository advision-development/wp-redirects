import { statusTone } from '../constants';

export default function StatusBadge( { status } ) {
	return (
		<span className={ `adv-redirects-badge is-${ statusTone( status ) }` }>
			{ status }
		</span>
	);
}

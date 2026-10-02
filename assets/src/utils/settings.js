export function parseExtensions( text ) {
	const seen = new Set();
	return String( text )
		.split( ',' )
		.map( ( value ) => value.trim().replace( /^\.+/, '' ).toLowerCase() )
		.filter(
			( value ) =>
				/^[a-z0-9]{1,10}$/.test( value ) &&
				! seen.has( value ) &&
				seen.add( value )
		);
}

export const formatExtensions = ( list ) => ( list || [] ).join( ', ' );

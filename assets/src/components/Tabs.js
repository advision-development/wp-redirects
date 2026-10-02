import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Tabs( { tabs, selected, onSelect } ) {
	const refs = useRef( {} );

	const onKeyDown = ( event, index ) => {
		const last = tabs.length - 1;
		const next = {
			ArrowRight: index === last ? 0 : index + 1,
			ArrowLeft: index === 0 ? last : index - 1,
			Home: 0,
			End: last,
		}[ event.key ];
		if ( next === undefined ) {
			return;
		}
		event.preventDefault();
		onSelect( tabs[ next ].name );
		refs.current[ tabs[ next ].name ]?.focus();
	};

	return (
		<div
			className="adv-redirects-tabs"
			role="tablist"
			aria-label={ __( 'Redirect sections', 'wp-redirects' ) }
		>
			{ tabs.map( ( tab, index ) => (
				<button
					key={ tab.name }
					ref={ ( element ) =>
						( refs.current[ tab.name ] = element )
					}
					type="button"
					role="tab"
					id={ `adv-redirects-tab-${ tab.name }` }
					aria-controls={ `adv-redirects-panel-${ tab.name }` }
					aria-selected={ selected === tab.name }
					tabIndex={ selected === tab.name ? 0 : -1 }
					className={ `adv-redirects-tabs__tab${ selected === tab.name ? ' is-active' : '' }` }
					onClick={ () => onSelect( tab.name ) }
					onKeyDown={ ( event ) => onKeyDown( event, index ) }
				>
					{ tab.title }
					{ tab.count !== undefined && (
						<span className="adv-redirects-tabs__count">
							{ tab.count }
						</span>
					) }
				</button>
			) ) }
		</div>
	);
}

import { Button, SelectControl } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { PAGE_SIZES } from '../utils/paging';

function rangeText( { start, end, total }, pageSize ) {
	if ( pageSize === 0 ) {
		return sprintf(
			/* translators: %d: number of redirects */
			_n( '%d redirect', '%d redirects', total, 'wp-redirects' ),
			total
		);
	}
	return sprintf(
		/* translators: 1: first row number, 2: last row number, 3: total rows. The dash is an en dash. */
		__( '%1$d–%2$d of %3$d', 'wp-redirects' ),
		start + 1,
		end,
		total
	);
}

export default function RulesPager( {
	info,
	pageSize,
	onPage,
	onPageSize,
	label,
} ) {
	const previous = useRef( null );
	const next = useRef( null );
	// Which button to focus after the page changed, because the one that was
	// just used may now be disabled.
	const focusAfter = useRef( null );

	useEffect( () => {
		const target = focusAfter.current;
		focusAfter.current = null;
		if ( target === 'previous' ) {
			previous.current?.focus();
		} else if ( target === 'next' ) {
			next.current?.focus();
		}
	}, [ info.page ] );

	const atStart = info.page === 0;
	const atEnd = info.page >= info.pageCount - 1;

	const go = ( direction ) => {
		const target = info.page + direction;
		if ( direction < 0 && target <= 0 ) {
			focusAfter.current = 'next';
		} else if ( direction > 0 && target >= info.pageCount - 1 ) {
			focusAfter.current = 'previous';
		}
		onPage( target );
	};

	return (
		<div className="adv-redirects-pager" role="group" aria-label={ label }>
			<span className="adv-redirects-pager__range" aria-live="polite">
				{ rangeText( info, pageSize ) }
			</span>
			<SelectControl
				__nextHasNoMarginBottom
				className="adv-redirects-pager__size"
				label={ __( 'Rows per page', 'wp-redirects' ) }
				labelPosition="side"
				value={ String( pageSize ) }
				options={ PAGE_SIZES.map( ( size ) => ( {
					value: String( size ),
					label:
						size === 0
							? __( 'All', 'wp-redirects' )
							: String( size ),
				} ) ) }
				onChange={ ( value ) => onPageSize( Number( value ) ) }
			/>
			<div className="adv-redirects-pager__nav">
				<Button
					ref={ previous }
					variant="secondary"
					size="compact"
					aria-label={ __( 'Previous page', 'wp-redirects' ) }
					disabled={ atStart }
					onClick={ () => go( -1 ) }
				>
					<span aria-hidden="true">‹ </span>
					{ __( 'Previous', 'wp-redirects' ) }
				</Button>
				<Button
					ref={ next }
					variant="secondary"
					size="compact"
					aria-label={ __( 'Next page', 'wp-redirects' ) }
					disabled={ atEnd }
					onClick={ () => go( 1 ) }
				>
					{ __( 'Next', 'wp-redirects' ) }
					<span aria-hidden="true"> ›</span>
				</Button>
			</div>
		</div>
	);
}

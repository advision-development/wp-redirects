import {
	Button,
	SearchControl,
	SelectControl,
	VisuallyHidden,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, STATUS_OPTIONS } from '../constants';
import { notifySaved } from '../utils/notifySaved';
import {
	pageOfIndex,
	paginate,
	readPageSize,
	writePageSize,
} from '../utils/paging';
import {
	DEFAULT_FILTERS,
	filterRules,
	isFiltered,
	moveItem,
	sortRules,
	visibleSelection,
} from '../utils/rules';
import ConfirmModal from './ConfirmModal';
import RuleEditRow from './RuleEditRow';
import RuleRow from './RuleRow';
import RulesPager from './RulesPager';

// Smallest page size: a pager is pointless at or below it.
const MIN_PAGE_ROWS = 25;

// Last row gone: the table may unmount, so fall back to the add form.
function focusQuickAdd() {
	document.querySelector( '.adv-redirects-quickadd__source input' )?.focus();
}

function SortableHeader( { label, column, sort, onSort, sortable } ) {
	if ( ! sortable ) {
		return <th scope="col">{ label }</th>;
	}
	const active = sort.orderby === column;
	let ariaSort = 'none';
	if ( active ) {
		ariaSort = sort.order === 'asc' ? 'ascending' : 'descending';
	}
	return (
		<th scope="col" aria-sort={ ariaSort }>
			<button
				type="button"
				className="adv-redirects-sort"
				onClick={ () =>
					onSort( {
						orderby: column,
						order: active && sort.order === 'asc' ? 'desc' : 'asc',
					} )
				}
			>
				{ label }
				<span aria-hidden="true">
					{ active && ( sort.order === 'asc' ? ' ↑' : ' ↓' ) }
				</span>
			</button>
		</th>
	);
}

export default function RulesTable( {
	mode,
	rules,
	highlightId,
	onUpdate,
	onRemove,
	onBulk,
	onReorder,
	notify,
} ) {
	const isRegex = mode === 'regex';
	const [ filters, setFilters ] = useState( DEFAULT_FILTERS );
	const [ sort, setSort ] = useState( { orderby: 'source', order: 'asc' } );
	// 0-based; clamped to the real range at render by paginate().
	const [ page, setPage ] = useState( 0 );
	const [ pageSize, setPageSize ] = useState( readPageSize );
	const [ selected, setSelected ] = useState( [] );
	const [ editingId, setEditingId ] = useState( null );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ dragIndex, setDragIndex ] = useState( null );
	const editButtons = useRef( {} );
	// The rule whose Edit button gets focus back once its edit row closes.
	const [ returnFocusId, setReturnFocusId ] = useState( null );

	useEffect( () => {
		if ( returnFocusId !== null && editingId === null ) {
			editButtons.current[ returnFocusId ]?.focus();
			setReturnFocusId( null );
		}
	}, [ returnFocusId, editingId ] );

	// Set when a move carried a rule onto another page, so focus can follow it.
	const moveFocus = useRef( null );
	useEffect( () => {
		const target = moveFocus.current;
		moveFocus.current = null;
		const buttons = target
			? document.querySelectorAll(
					`#adv-redirects-rule-${ target.id } .adv-redirects-order button`
				)
			: [];
		if ( buttons.length === 2 ) {
			const [ up, down ] = buttons;
			const [ first, second ] = target.forward
				? [ down, up ]
				: [ up, down ];
			( first.disabled ? second : first ).focus();
		}
	}, [ rules, page ] );

	const closeEdit = ( id ) => {
		setEditingId( null );
		setReturnFocusId( id );
	};

	const filtered = isFiltered( filters );
	const reorderable = isRegex && ! filtered;
	const visible = useMemo( () => {
		const list = filterRules( rules, filters );
		return isRegex ? list : sortRules( list, sort );
	}, [ rules, filters, sort, isRegex ] );
	const pageInfo = paginate( visible, page, pageSize );
	const pageRules = pageInfo.items;
	const pageIds = pageRules.map( ( rule ) => rule.id );
	// Only rows that are both ticked and on the current page take part in bulk
	// actions, so filtering or paging never leaves hidden rows selected.
	const activeSelection = visibleSelection( selected, pageIds );
	const allSelected =
		pageIds.length > 0 && activeSelection.length === pageIds.length;
	const showPager = visible.length > MIN_PAGE_ROWS;
	const pageIdsKey = pageIds.join( ',' );

	useEffect( () => {
		setSelected( ( current ) => {
			const next = visibleSelection(
				current,
				pageIdsKey ? pageIdsKey.split( ',' ).map( Number ) : []
			);
			return next.length === current.length ? current : next;
		} );
	}, [ pageIdsKey ] );

	// A rule picked by the Test URL tool may be on another page: show that page
	// so "Show rule" finds its row. Only reacts to a new match, never to paging.
	const latest = useRef( {} );
	latest.current = { visible, pageSize };
	useEffect( () => {
		if ( highlightId === null || highlightId === undefined ) {
			return;
		}
		const { visible: list, pageSize: size } = latest.current;
		const index = list.findIndex( ( rule ) => rule.id === highlightId );
		if ( index !== -1 ) {
			setPage( pageOfIndex( index, size ) );
		}
	}, [ highlightId ] );

	const colSpan = isRegex ? 9 : 8;
	const changeFilters = ( next ) => {
		setFilters( next );
		setPage( 0 );
	};
	const changeSort = ( next ) => {
		setSort( next );
		setPage( 0 );
	};
	const setFilter = ( key ) => ( value ) =>
		changeFilters( { ...filters, [ key ]: value } );
	const reportError = ( error ) =>
		notify( { status: 'error', message: errorMessage( error ) } );

	const toggleOne = ( id ) =>
		setSelected( ( current ) =>
			current.includes( id )
				? current.filter( ( value ) => value !== id )
				: [ ...current, id ]
		);

	const runBulk = async ( action ) => {
		try {
			const result = await onBulk( action, activeSelection );
			setSelected( [] );
			if ( result.skipped.length ) {
				notify( {
					status: 'error',
					message: sprintf(
						/* translators: 1: number skipped, 2: reason */
						_n(
							'%1$d redirect was skipped: %2$s',
							'%1$d redirects were skipped: %2$s',
							result.skipped.length,
							'wp-redirects'
						),
						result.skipped.length,
						result.skipped[ 0 ].message
					),
				} );
			} else {
				notify( {
					message: sprintf(
						/* translators: %d: number of redirects */
						_n(
							'%d redirect updated.',
							'%d redirects updated.',
							result.updated,
							'wp-redirects'
						),
						result.updated
					),
				} );
			}
		} catch ( error ) {
			reportError( error );
		}
	};

	const move = ( from, to ) => {
		if ( to < 0 || to >= rules.length ) {
			return;
		}
		// Follow the moved rule to its new page and put focus back on it.
		if ( to < pageInfo.start || to >= pageInfo.end ) {
			setPage( pageOfIndex( to, pageSize ) );
			moveFocus.current = { id: rules[ from ].id, forward: to > from };
		}
		onReorder(
			moveItem(
				rules.map( ( rule ) => rule.id ),
				from,
				to
			)
		);
	};

	const saveEdit = async ( rule, patch ) => {
		const result = await onUpdate( rule.id, patch );
		closeEdit( rule.id );
		notifySaved( result, {
			notify,
			onUpdate,
			message: __( 'Redirect updated.', 'wp-redirects' ),
		} );
	};

	const dragPropsFor = ( index ) =>
		reorderable
			? {
					draggable: true,
					onDragStart: ( event ) => {
						// Firefox only starts a drag when data is set.
						event.dataTransfer.setData(
							'text/plain',
							String( index )
						);
						event.dataTransfer.effectAllowed = 'move';
						setDragIndex( index );
					},
					onDragOver: ( event ) => event.preventDefault(),
					onDrop: ( event ) => {
						event.preventDefault();
						if ( dragIndex !== null && dragIndex !== index ) {
							move( dragIndex, index );
						}
						setDragIndex( null );
					},
					onDragEnd: () => setDragIndex( null ),
				}
			: {};

	let emptyMessage = isRegex
		? __( 'No regex redirects yet.', 'wp-redirects' )
		: __( 'No exact redirects yet.', 'wp-redirects' );
	if ( filtered ) {
		emptyMessage = __(
			'No redirects match these filters.',
			'wp-redirects'
		);
	}

	return (
		<div className="adv-redirects-tablewrap">
			<div className="adv-redirects-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={
						isRegex
							? __( 'Search regex redirects', 'wp-redirects' )
							: __( 'Search redirects', 'wp-redirects' )
					}
					value={ filters.search }
					onChange={ setFilter( 'search' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by status', 'wp-redirects' ) }
					value={ filters.status }
					options={ [
						{
							value: 'all',
							label: __( 'All statuses', 'wp-redirects' ),
						},
						...STATUS_OPTIONS.map( ( option ) => ( {
							value: String( option.value ),
							label: String( option.value ),
						} ) ),
					] }
					onChange={ setFilter( 'status' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by state', 'wp-redirects' ) }
					value={ filters.enabled }
					options={ [
						{
							value: 'all',
							label: __( 'Enabled and disabled', 'wp-redirects' ),
						},
						{
							value: 'enabled',
							label: __( 'Enabled', 'wp-redirects' ),
						},
						{
							value: 'disabled',
							label: __( 'Disabled', 'wp-redirects' ),
						},
					] }
					onChange={ setFilter( 'enabled' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by origin', 'wp-redirects' ) }
					value={ filters.origin }
					options={ [
						{
							value: 'all',
							label: __( 'Manual and automatic', 'wp-redirects' ),
						},
						{
							value: 'manual',
							label: __( 'Added manually', 'wp-redirects' ),
						},
						{
							value: 'auto',
							label: __(
								'Created on slug change',
								'wp-redirects'
							),
						},
					] }
					onChange={ setFilter( 'origin' ) }
				/>
				{ activeSelection.length > 0 && (
					<div
						className="adv-redirects-bulk"
						role="group"
						aria-label={ __( 'Bulk actions', 'wp-redirects' ) }
					>
						<span>
							{ sprintf(
								/* translators: %d: number of selected redirects */
								_n(
									'%d redirect selected',
									'%d redirects selected',
									activeSelection.length,
									'wp-redirects'
								),
								activeSelection.length
							) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => runBulk( 'enable' ) }
						>
							{ __( 'Enable', 'wp-redirects' ) }
						</Button>
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => runBulk( 'disable' ) }
						>
							{ __( 'Disable', 'wp-redirects' ) }
						</Button>
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							onClick={ () => setConfirmDelete( true ) }
						>
							{ __( 'Delete', 'wp-redirects' ) }
						</Button>
					</div>
				) }
			</div>

			{ visible.length === 0 ? (
				<p className="adv-redirects-empty">
					{ emptyMessage }
					{ filtered && (
						<>
							{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									changeFilters( DEFAULT_FILTERS )
								}
							>
								{ __( 'Clear filters', 'wp-redirects' ) }
							</Button>
						</>
					) }
				</p>
			) : (
				<table className="adv-redirects-table">
					<thead>
						<tr>
							<td className="adv-redirects-col-check">
								<input
									type="checkbox"
									checked={ allSelected }
									onChange={ () =>
										setSelected(
											allSelected ? [] : pageIds
										)
									}
									aria-label={ __(
										'Select all',
										'wp-redirects'
									) }
								/>
							</td>
							{ isRegex && (
								<th scope="col">
									{ __( 'Order', 'wp-redirects' ) }
								</th>
							) }
							<th scope="col">
								{ __( 'Enabled', 'wp-redirects' ) }
							</th>
							<SortableHeader
								label={ __( 'Source', 'wp-redirects' ) }
								column="source"
								sort={ sort }
								onSort={ changeSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Target', 'wp-redirects' ) }
								column="target"
								sort={ sort }
								onSort={ changeSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Status', 'wp-redirects' ) }
								column="status_code"
								sort={ sort }
								onSort={ changeSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Hits', 'wp-redirects' ) }
								column="hits"
								sort={ sort }
								onSort={ changeSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Last hit', 'wp-redirects' ) }
								column="last_hit_at"
								sort={ sort }
								onSort={ changeSort }
								sortable={ ! isRegex }
							/>
							<th scope="col">
								<VisuallyHidden>
									{ __( 'Actions', 'wp-redirects' ) }
								</VisuallyHidden>
							</th>
						</tr>
					</thead>
					<tbody>
						{ pageRules.map( ( rule, offset ) => {
							// Position in the whole list, not on this page.
							const index = pageInfo.start + offset;
							return editingId === rule.id ? (
								<RuleEditRow
									key={ rule.id }
									rule={ rule }
									colSpan={ colSpan }
									onSave={ ( patch ) =>
										saveEdit( rule, patch )
									}
									onCancel={ () => closeEdit( rule.id ) }
								/>
							) : (
								<RuleRow
									key={ rule.id }
									rule={ rule }
									isRegex={ isRegex }
									index={ index }
									total={ visible.length }
									selected={ selected.includes( rule.id ) }
									highlighted={ highlightId === rule.id }
									reorderable={ reorderable }
									dragProps={ dragPropsFor( index ) }
									onSelect={ toggleOne }
									onToggle={ ( target ) =>
										onUpdate( target.id, {
											enabled: ! target.enabled,
										} ).catch( reportError )
									}
									onEdit={ setEditingId }
									editButtonRef={ ( element ) => {
										editButtons.current[ rule.id ] =
											element;
									} }
									onDelete={ async ( target ) => {
										const neighbour =
											visible[ index + 1 ] ||
											visible[ index - 1 ];
										const deleted =
											await onRemove( target );
										// Failed: the row is restored, so
										// focus goes back to it.
										if ( ! deleted ) {
											setReturnFocusId( target.id );
										} else if ( neighbour ) {
											setReturnFocusId( neighbour.id );
										} else {
											focusQuickAdd();
										}
									} }
									onMove={ move }
									onFixChain={ ( target ) =>
										onUpdate( target.id, {
											target: target.chain.final,
										} )
											.then( () =>
												notify( {
													message: __(
														'Redirect now points to the final destination.',
														'wp-redirects'
													),
												} )
											)
											.catch( reportError )
									}
								/>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ showPager && (
				<RulesPager
					info={ pageInfo }
					pageSize={ pageSize }
					label={
						isRegex
							? __( 'Regex redirects pages', 'wp-redirects' )
							: __( 'Exact redirects pages', 'wp-redirects' )
					}
					onPage={ setPage }
					onPageSize={ ( size ) => {
						setPageSize( size );
						writePageSize( size );
						setPage( 0 );
					} }
				/>
			) }

			{ isRegex && filtered && (
				<p className="description">
					{ __(
						'Clear the search and filters to reorder.',
						'wp-redirects'
					) }
				</p>
			) }

			{ confirmDelete && (
				<ConfirmModal
					title={ __( 'Delete redirects?', 'wp-redirects' ) }
					message={ sprintf(
						/* translators: %d: number of redirects */
						_n(
							'Delete %d redirect? This cannot be undone.',
							'Delete %d redirects? This cannot be undone.',
							activeSelection.length,
							'wp-redirects'
						),
						activeSelection.length
					) }
					confirmLabel={ __( 'Delete', 'wp-redirects' ) }
					onCancel={ () => setConfirmDelete( false ) }
					onConfirm={ () => {
						setConfirmDelete( false );
						runBulk( 'delete' );
					} }
				/>
			) }
		</div>
	);
}

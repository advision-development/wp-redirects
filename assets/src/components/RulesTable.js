import {
	Button,
	SearchControl,
	SelectControl,
	VisuallyHidden,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, STATUS_OPTIONS } from '../constants';
import { notifySaved } from '../utils/notifySaved';
import {
	DEFAULT_FILTERS,
	filterRules,
	isFiltered,
	moveItem,
	sortRules,
} from '../utils/rules';
import ConfirmModal from './ConfirmModal';
import RuleEditRow from './RuleEditRow';
import RuleRow from './RuleRow';

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
	const [ selected, setSelected ] = useState( [] );
	const [ editingId, setEditingId ] = useState( null );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ dragIndex, setDragIndex ] = useState( null );

	useEffect( () => {
		setSelected( ( current ) =>
			current.filter( ( id ) => rules.some( ( rule ) => rule.id === id ) )
		);
	}, [ rules ] );

	const filtered = isFiltered( filters );
	const reorderable = isRegex && ! filtered;
	const visible = useMemo( () => {
		const list = filterRules( rules, filters );
		return isRegex ? list : sortRules( list, sort );
	}, [ rules, filters, sort, isRegex ] );
	const visibleIds = visible.map( ( rule ) => rule.id );
	const allSelected =
		visibleIds.length > 0 &&
		visibleIds.every( ( id ) => selected.includes( id ) );
	const colSpan = isRegex ? 9 : 8;
	const setFilter = ( key ) => ( value ) =>
		setFilters( ( current ) => ( { ...current, [ key ]: value } ) );
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
			const result = await onBulk( action, selected );
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
		setEditingId( null );
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
					onDragStart: () => setDragIndex( index ),
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
				{ selected.length > 0 && (
					<div
						className="adv-redirects-bulk"
						role="group"
						aria-label={ __( 'Bulk actions', 'wp-redirects' ) }
					>
						<span>
							{
								/* translators: %d: number selected */ sprintf(
									_n(
										'%d selected',
										'%d selected',
										selected.length,
										'wp-redirects'
									),
									selected.length
								)
							}
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
				<p className="adv-redirects-empty">{ emptyMessage }</p>
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
											allSelected ? [] : visibleIds
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
							<th scope="col">{ __( 'On', 'wp-redirects' ) }</th>
							<SortableHeader
								label={ __( 'Source', 'wp-redirects' ) }
								column="source"
								sort={ sort }
								onSort={ setSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Target', 'wp-redirects' ) }
								column="target"
								sort={ sort }
								onSort={ setSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Status', 'wp-redirects' ) }
								column="status_code"
								sort={ sort }
								onSort={ setSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Hits', 'wp-redirects' ) }
								column="hits"
								sort={ sort }
								onSort={ setSort }
								sortable={ ! isRegex }
							/>
							<SortableHeader
								label={ __( 'Last hit', 'wp-redirects' ) }
								column="last_hit_at"
								sort={ sort }
								onSort={ setSort }
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
						{ visible.map( ( rule, index ) =>
							editingId === rule.id ? (
								<RuleEditRow
									key={ rule.id }
									rule={ rule }
									colSpan={ colSpan }
									onSave={ ( patch ) =>
										saveEdit( rule, patch )
									}
									onCancel={ () => setEditingId( null ) }
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
									onDelete={ onRemove }
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
							)
						) }
					</tbody>
				</table>
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
							selected.length,
							'wp-redirects'
						),
						selected.length
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

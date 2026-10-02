import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { visibleSelection } from '../utils/rules';
import { parseGmt, timeAgo } from '../utils/time';
import ConfirmModal from './ConfirmModal';

const PER_PAGE = 20;

export default function NotFoundTab( {
	settings,
	notify,
	onCreateRedirect,
	onOpenSettings,
} ) {
	const [ query, setQuery ] = useState( {
		page: 1,
		search: '',
		orderby: 'hits',
		order: 'desc',
	} );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ data, setData ] = useState( { items: [], total: 0, pages: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ loaded, setLoaded ] = useState( false );
	const latestRequest = useRef( 0 );
	const [ selected, setSelected ] = useState( [] );
	const [ confirm, setConfirm ] = useState( null );

	useEffect( () => {
		const timer = setTimeout( () => {
			const search = searchInput.trim();
			setQuery( ( current ) =>
				current.search === search
					? current
					: { ...current, search, page: 1 }
			);
		}, 300 );
		return () => clearTimeout( timer );
	}, [ searchInput ] );

	const load = useCallback( async () => {
		latestRequest.current += 1;
		const request = latestRequest.current;
		setLoading( true );
		try {
			const result = await api.list404s( {
				...query,
				perPage: PER_PAGE,
			} );
			if ( request !== latestRequest.current ) {
				// A newer request superseded this one.
				return;
			}
			if (
				result.items.length === 0 &&
				result.pages > 0 &&
				query.page > result.pages
			) {
				// The last row of the last page was removed: step back a page.
				setQuery( ( current ) => ( {
					...current,
					page: result.pages,
				} ) );
				return;
			}
			setData( result );
			setSelected( [] );
		} catch ( error ) {
			if ( request === latestRequest.current ) {
				notify( { status: 'error', message: errorMessage( error ) } );
			}
		} finally {
			if ( request === latestRequest.current ) {
				setLoading( false );
				setLoaded( true );
			}
		}
	}, [ query, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const run = async ( action, successMessage ) => {
		try {
			await action();
			notify( { message: successMessage } );
			load();
		} catch ( error ) {
			notify( { status: 'error', message: errorMessage( error ) } );
		}
	};

	const sortBy = ( orderby ) =>
		setQuery( ( current ) => ( {
			...current,
			orderby,
			order:
				current.orderby === orderby && current.order === 'desc'
					? 'asc'
					: 'desc',
			page: 1,
		} ) );

	const sortArrow = ( column ) => {
		if ( query.orderby !== column ) {
			return null;
		}
		return (
			<span aria-hidden="true">
				{ query.order === 'asc' ? ' ↑' : ' ↓' }
			</span>
		);
	};

	const ariaSort = ( column ) => {
		if ( query.orderby !== column ) {
			return 'none';
		}
		return query.order === 'asc' ? 'ascending' : 'descending';
	};

	const visibleIds = loaded ? data.items.map( ( item ) => item.id ) : [];
	const selectedVisible = visibleSelection( selected, visibleIds );
	const allSelected =
		visibleIds.length > 0 &&
		visibleIds.every( ( id ) => selected.includes( id ) );

	return (
		<>
			{ settings && ! settings.log_404 && (
				<Notice status="warning" isDismissible={ false }>
					{ __( '404 logging is turned off.', 'wp-redirects' ) }{ ' ' }
					<Button variant="link" onClick={ onOpenSettings }>
						{ __( 'Turn it on in Settings', 'wp-redirects' ) }
					</Button>
				</Notice>
			) }
			<section className="adv-redirects-card">
				<div className="adv-redirects-toolbar">
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search 404s', 'wp-redirects' ) }
						value={ searchInput }
						onChange={ setSearchInput }
					/>
					<div className="adv-redirects-bulk">
						{ loaded && loading && <Spinner /> }
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							disabled={ selectedVisible.length === 0 }
							onClick={ () =>
								setConfirm( {
									type: 'selected',
									ids: selectedVisible,
								} )
							}
						>
							{ __( 'Delete selected', 'wp-redirects' ) }
						</Button>
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							disabled={ data.total === 0 }
							onClick={ () => setConfirm( { type: 'all' } ) }
						>
							{ __( 'Clear log', 'wp-redirects' ) }
						</Button>
					</div>
				</div>

				{ ! loaded && (
					<div className="adv-redirects-loading">
						<Spinner />
					</div>
				) }
				{ loaded && ! loading && data.items.length === 0 && (
					<p className="adv-redirects-empty">
						{ query.search
							? __( 'No 404s match this search.', 'wp-redirects' )
							: __( 'No 404s recorded. Nice.', 'wp-redirects' ) }
					</p>
				) }
				{ loaded && data.items.length > 0 && (
					<div
						className={ `adv-redirects-tablewrap${
							loading ? ' is-loading' : ''
						}` }
						aria-busy={ loading }
					>
						<table className="adv-redirects-table">
							<thead>
								<tr>
									<td className="adv-redirects-col-check">
										<input
											type="checkbox"
											checked={ allSelected }
											onChange={ () =>
												setSelected(
													allSelected
														? []
														: visibleIds
												)
											}
											aria-label={ __(
												'Select all',
												'wp-redirects'
											) }
										/>
									</td>
									<th scope="col">
										{ __( 'Path', 'wp-redirects' ) }
									</th>
									<th
										scope="col"
										aria-sort={ ariaSort( 'hits' ) }
									>
										<button
											type="button"
											className="adv-redirects-sort"
											onClick={ () => sortBy( 'hits' ) }
										>
											{ __( 'Hits', 'wp-redirects' ) }
											{ sortArrow( 'hits' ) }
										</button>
									</th>
									<th
										scope="col"
										aria-sort={ ariaSort( 'last_seen' ) }
									>
										<button
											type="button"
											className="adv-redirects-sort"
											onClick={ () =>
												sortBy( 'last_seen' )
											}
										>
											{ __(
												'Last seen',
												'wp-redirects'
											) }
											{ sortArrow( 'last_seen' ) }
										</button>
									</th>
									<th scope="col">
										{ __(
											'Last referrer',
											'wp-redirects'
										) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __( 'Actions', 'wp-redirects' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( item ) => {
									const lastSeen = parseGmt( item.last_seen );
									const selectLabel = sprintf(
										/* translators: %s: path */
										__( 'Select %s', 'wp-redirects' ),
										item.path
									);
									return (
										<tr
											key={ item.id }
											className="adv-redirects-row"
										>
											<td className="adv-redirects-col-check">
												<input
													type="checkbox"
													checked={ selected.includes(
														item.id
													) }
													onChange={ () =>
														setSelected(
															( current ) =>
																current.includes(
																	item.id
																)
																	? current.filter(
																			(
																				id
																			) =>
																				id !==
																				item.id
																		)
																	: [
																			...current,
																			item.id,
																		]
														)
													}
													aria-label={ selectLabel }
												/>
											</td>
											<td className="adv-redirects-col-path">
												<code>{ item.path }</code>
											</td>
											<td className="adv-redirects-col-hits">
												{ item.hits.toLocaleString() }
											</td>
											<td className="adv-redirects-col-seen">
												<span
													title={
														lastSeen
															? lastSeen.toLocaleString()
															: undefined
													}
												>
													{ timeAgo(
														item.last_seen
													) }
												</span>
											</td>
											<td
												className="adv-redirects-referrer"
												title={
													item.last_referrer ||
													undefined
												}
											>
												{ item.last_referrer || '—' }
											</td>
											<td className="adv-redirects-col-actions">
												<Button
													variant="secondary"
													size="compact"
													aria-label={ sprintf(
														/* translators: %s: path */
														__(
															'Create redirect for %s',
															'wp-redirects'
														),
														item.path
													) }
													onClick={ () =>
														onCreateRedirect(
															item.path
														)
													}
												>
													{ __(
														'Create redirect',
														'wp-redirects'
													) }
												</Button>
												<Button
													variant="tertiary"
													size="compact"
													isDestructive
													aria-label={ sprintf(
														/* translators: %s: path */
														__(
															'Delete %s',
															'wp-redirects'
														),
														item.path
													) }
													onClick={ () =>
														run(
															() =>
																api.delete404(
																	item.id
																),
															__(
																'Entry deleted.',
																'wp-redirects'
															)
														)
													}
												>
													{ __(
														'Delete',
														'wp-redirects'
													) }
												</Button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }

				{ data.pages > 1 && (
					<nav
						className="adv-redirects-pagination"
						aria-label={ __( '404 log pages', 'wp-redirects' ) }
					>
						<Button
							variant="secondary"
							size="compact"
							disabled={ query.page <= 1 }
							onClick={ () =>
								setQuery( ( current ) => ( {
									...current,
									page: current.page - 1,
								} ) )
							}
						>
							{ __( 'Previous', 'wp-redirects' ) }
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$d of %2$d', 'wp-redirects' ),
								query.page,
								data.pages
							) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							disabled={ query.page >= data.pages }
							onClick={ () =>
								setQuery( ( current ) => ( {
									...current,
									page: current.page + 1,
								} ) )
							}
						>
							{ __( 'Next', 'wp-redirects' ) }
						</Button>
					</nav>
				) }
			</section>

			{ confirm && (
				<ConfirmModal
					title={
						confirm.type === 'all'
							? __( 'Clear the 404 log?', 'wp-redirects' )
							: __( 'Delete selected entries?', 'wp-redirects' )
					}
					message={
						confirm.type === 'all'
							? __(
									'Every logged 404 will be removed. This cannot be undone.',
									'wp-redirects'
								)
							: sprintf(
									/* translators: %d: number of entries */
									_n(
										'Delete %d entry? This cannot be undone.',
										'Delete %d entries? This cannot be undone.',
										confirm.ids.length,
										'wp-redirects'
									),
									confirm.ids.length
								)
					}
					confirmLabel={
						confirm.type === 'all'
							? __( 'Clear log', 'wp-redirects' )
							: __( 'Delete', 'wp-redirects' )
					}
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						const { type, ids } = confirm;
						setConfirm( null );
						run(
							type === 'all'
								? api.clear404s
								: () => api.bulkDelete404s( ids ),
							type === 'all'
								? __( '404 log cleared.', 'wp-redirects' )
								: __( 'Entries deleted.', 'wp-redirects' )
						);
					} }
				/>
			) }
		</>
	);
}

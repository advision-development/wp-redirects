import { __, sprintf } from '@wordpress/i18n';
import { groupPreview, NOTE_LABELS } from '../utils/redirectionImport';
import StatusBadge from './StatusBadge';

function Notes( { entry } ) {
	if ( ! entry.notes || ! entry.notes.length ) {
		return null;
	}
	return (
		<ul className="adv-redirects-import__notes">
			{ entry.notes.map( ( code ) => (
				<li key={ code }>{ NOTE_LABELS[ code ] || code }</li>
			) ) }
		</ul>
	);
}

function Target( { rule } ) {
	if ( ! rule ) {
		return null;
	}
	return rule.target ? (
		<code>{ rule.target }</code>
	) : (
		<span className="adv-redirects-muted">
			{ __( 'No target', 'wp-redirects' ) }
		</span>
	);
}

function Group( { title, entries, render, open = false } ) {
	if ( ! entries.length ) {
		return null;
	}
	return (
		<details className="adv-redirects-import__group" open={ open }>
			<summary>{ sprintf( title, entries.length ) }</summary>
			<table className="adv-redirects-table">
				<tbody>
					{ entries.map( ( entry ) => (
						<tr key={ `${ entry.status }-${ entry.index }` }>
							<td className="adv-redirects-col-source">
								<code>{ entry.source }</code>
							</td>
							<td>{ render( entry ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</details>
	);
}

export default function ImportPreview( { preview } ) {
	const groups = groupPreview( preview );

	return (
		<div className="adv-redirects-import__preview">
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'New (%d)', 'wp-redirects' ) }
				entries={ groups.new }
				open={ groups.new.length <= 20 }
				render={ ( entry ) => (
					<>
						<StatusBadge status={ entry.rule.status_code } />{ ' ' }
						<Target rule={ entry.rule } />
						{ entry.rule.origin === 'auto' && (
							<span className="adv-redirects-flag">
								{ __( 'Auto', 'wp-redirects' ) }
							</span>
						) }
						{ ! entry.rule.enabled && (
							<span className="adv-redirects-flag">
								{ __( 'Disabled', 'wp-redirects' ) }
							</span>
						) }
						<Notes entry={ entry } />
					</>
				) }
			/>
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'Will overwrite (%d)', 'wp-redirects' ) }
				entries={ groups.overwrite }
				open
				render={ ( entry ) => (
					<>
						<span className="adv-redirects-muted">
							<StatusBadge status={ entry.current.status_code } />{ ' ' }
							{ entry.current.target ? (
								<code>{ entry.current.target }</code>
							) : (
								__( 'No target', 'wp-redirects' )
							) }
						</span>
						{ ' → ' }
						<StatusBadge status={ entry.rule.status_code } />{ ' ' }
						<Target rule={ entry.rule } />
						<Notes entry={ entry } />
					</>
				) }
			/>
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'Chain warnings (%d)', 'wp-redirects' ) }
				entries={ groups.warnings }
				render={ ( entry ) => (
					<code>{ entry.warnings[ 0 ].hops.join( ' → ' ) }</code>
				) }
			/>
			<Group
				/* translators: %d: number of entries */
				title={ __( 'Skipped (%d)', 'wp-redirects' ) }
				entries={ groups.skipped }
				open
				render={ ( entry ) => (
					<span className="is-error">{ entry.error.message }</span>
				) }
			/>
			<Group
				/* translators: %d: number of entries */
				title={ __( 'Superseded (%d)', 'wp-redirects' ) }
				entries={ groups.superseded }
				render={ () => (
					<span className="adv-redirects-muted">
						{ __(
							'A later entry in the file uses the same source and wins.',
							'wp-redirects'
						) }
					</span>
				) }
			/>
		</div>
	);
}

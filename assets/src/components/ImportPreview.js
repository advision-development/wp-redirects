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

// What an overwrite changes besides the target and status code: the enabled
// state and the note.
function OverwriteChanges( { entry } ) {
	const { current, rule } = entry;
	const wasEnabled = Boolean( current.enabled );
	const willBeEnabled = Boolean( rule.enabled );
	const oldNote = current.note || '';
	const newNote = rule.note || '';
	if ( wasEnabled === willBeEnabled && oldNote === newNote ) {
		return null;
	}
	return (
		<>
			{ wasEnabled !== willBeEnabled && (
				<span className="adv-redirects-flag">
					{ willBeEnabled
						? __( 'Disabled → Enabled', 'wp-redirects' )
						: __( 'Enabled → Disabled', 'wp-redirects' ) }
				</span>
			) }
			{ oldNote !== newNote && (
				<span className="adv-redirects-muted">
					{ sprintf(
						/* translators: 1: the existing note, 2: the imported note */
						__( 'Note: “%1$s” → “%2$s”', 'wp-redirects' ),
						oldNote,
						newNote
					) }
				</span>
			) }
		</>
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
				<thead className="screen-reader-text">
					<tr>
						<th scope="col">{ __( 'Source', 'wp-redirects' ) }</th>
						<th scope="col">{ __( 'Result', 'wp-redirects' ) }</th>
					</tr>
				</thead>
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
						<OverwriteChanges entry={ entry } />
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
				render={ ( entry ) => (
					<span className="adv-redirects-muted">
						{ entry.error
							? entry.error.message
							: __(
									'Another entry in the file uses the same source.',
									'wp-redirects'
								) }
					</span>
				) }
			/>
		</div>
	);
}

import { Button, Notice, Spinner } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { splitByType } from '../utils/rules';
import QuickAddForm from './QuickAddForm';
import RulesTable from './RulesTable';
import TestUrlBar from './TestUrlBar';

export default function RedirectsTab( {
	redirects,
	notify,
	prefill,
	onPrefillUsed,
} ) {
	const [ testPath, setTestPath ] = useState( '' );
	const [ highlightId, setHighlightId ] = useState( null );
	const { exact, regex } = useMemo(
		() => splitByType( redirects.items ),
		[ redirects.items ]
	);

	if ( redirects.loading ) {
		return (
			<div className="adv-redirects-loading">
				<Spinner />
			</div>
		);
	}

	const errorNotice = redirects.error ? (
		<Notice status="error" isDismissible={ false }>
			{ redirects.error }{ ' ' }
			<Button variant="link" onClick={ redirects.reload }>
				{ __( 'Try again', 'wp-redirects' ) }
			</Button>
		</Notice>
	) : null;

	// With nothing loaded yet there is no content to keep, so show only the error.
	if ( errorNotice && redirects.items.length === 0 ) {
		return errorNotice;
	}

	const tableProps = {
		highlightId,
		notify,
		onUpdate: redirects.update,
		onRemove: redirects.remove,
		onBulk: redirects.bulk,
		onReorder: redirects.reorder,
	};

	return (
		<>
			{ errorNotice }
			<section className="adv-redirects-card">
				<TestUrlBar
					value={ testPath }
					onChange={ setTestPath }
					onResult={ setHighlightId }
					rules={ redirects.items }
				/>
			</section>
			<section className="adv-redirects-card">
				<QuickAddForm
					onCreate={ redirects.create }
					onUpdate={ redirects.update }
					notify={ notify }
					testPath={ testPath }
					prefill={ prefill }
					onPrefillUsed={ onPrefillUsed }
				/>
			</section>
			{ redirects.items.length === 0 ? (
				<section className="adv-redirects-card adv-redirects-emptystate">
					<h2>{ __( 'No redirects yet', 'wp-redirects' ) }</h2>
					<p>
						{ __(
							'Add a source path and where it should go. Use Exact for single URLs, or Regex to match many URLs at once (for example ^/blog/(.*)$ → /news/$1).',
							'wp-redirects'
						) }
					</p>
				</section>
			) : (
				<>
					<section className="adv-redirects-card">
						<h2 className="adv-redirects-card__title">
							{ __( 'Exact redirects', 'wp-redirects' ) }
						</h2>
						<RulesTable
							mode="exact"
							rules={ exact }
							{ ...tableProps }
						/>
					</section>
					{ regex.length > 0 && (
						<section className="adv-redirects-card">
							<h2 className="adv-redirects-card__title">
								{ __( 'Regex redirects', 'wp-redirects' ) }
							</h2>
							<p className="description">
								{ __(
									'Checked in order after exact redirects. The first match wins.',
									'wp-redirects'
								) }
							</p>
							<RulesTable
								mode="regex"
								rules={ regex }
								{ ...tableProps }
							/>
						</section>
					) }
					<p className="adv-redirects-footnote">
						{ __(
							'Hit counts refresh every 5 minutes.',
							'wp-redirects'
						) }
					</p>
				</>
			) }
		</>
	);
}

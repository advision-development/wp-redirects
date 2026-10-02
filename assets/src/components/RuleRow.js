import { Button, FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	chevronDown,
	chevronUp,
	dragHandle,
	pencil,
	trash,
} from '@wordpress/icons';
import { isGone } from '../constants';
import { parseGmt, timeAgo } from '../utils/time';
import StatusBadge from './StatusBadge';

export default function RuleRow( {
	rule,
	isRegex,
	index,
	total,
	selected,
	highlighted,
	reorderable,
	dragProps,
	onSelect,
	onToggle,
	onEdit,
	onDelete,
	onMove,
	onFixChain,
} ) {
	const lastHit = parseGmt( rule.last_hit_at );

	return (
		<tr
			className={ [
				'adv-redirects-row',
				highlighted && 'is-highlighted',
				! rule.enabled && 'is-disabled',
			]
				.filter( Boolean )
				.join( ' ' ) }
			{ ...dragProps }
		>
			<td className="adv-redirects-col-check">
				<input
					type="checkbox"
					checked={ selected }
					onChange={ () => onSelect( rule.id ) }
					aria-label={ sprintf(
						/* translators: %s: redirect source */
						__( 'Select %s', 'wp-redirects' ),
						rule.source
					) }
				/>
			</td>
			{ isRegex && (
				<td className="adv-redirects-col-order">
					{ reorderable && (
						<span
							className="adv-redirects-handle"
							aria-hidden="true"
						>
							{ dragHandle }
						</span>
					) }
					<span className="adv-redirects-position">
						{ index + 1 }
					</span>
					<Button
						size="small"
						icon={ chevronUp }
						label={ __( 'Move up', 'wp-redirects' ) }
						disabled={ ! reorderable || index === 0 }
						onClick={ () => onMove( index, index - 1 ) }
					/>
					<Button
						size="small"
						icon={ chevronDown }
						label={ __( 'Move down', 'wp-redirects' ) }
						disabled={ ! reorderable || index === total - 1 }
						onClick={ () => onMove( index, index + 1 ) }
					/>
				</td>
			) }
			<td className="adv-redirects-col-toggle">
				<FormToggle
					checked={ rule.enabled }
					onChange={ () => onToggle( rule ) }
					aria-label={ sprintf(
						/* translators: %s: redirect source */
						__( 'Enable %s', 'wp-redirects' ),
						rule.source
					) }
				/>
			</td>
			<td className="adv-redirects-col-source">
				<code>{ rule.source }</code>
				{ rule.note && (
					<div className="adv-redirects-note">{ rule.note }</div>
				) }
			</td>
			<td className="adv-redirects-col-target">
				{ isGone( rule.status_code ) ? (
					<span className="adv-redirects-muted">
						{ __( 'No target', 'wp-redirects' ) }
					</span>
				) : (
					<code>{ rule.target }</code>
				) }
				<div className="adv-redirects-flags">
					{ rule.origin === 'auto' && (
						<span className="adv-redirects-flag">
							{ __( 'Auto', 'wp-redirects' ) }
						</span>
					) }
					{ rule.chain && rule.chain.loop && (
						<span
							className="adv-redirects-flag is-error"
							title={ rule.chain.hops.join( ' → ' ) }
						>
							{ __( 'Loop', 'wp-redirects' ) }
						</span>
					) }
					{ rule.chain && ! rule.chain.loop && (
						<>
							<span
								className="adv-redirects-flag is-warning"
								title={ rule.chain.hops.join( ' → ' ) }
							>
								{ __( 'Chain', 'wp-redirects' ) }
							</span>
							<Button
								variant="link"
								onClick={ () => onFixChain( rule ) }
							>
								{
									/* translators: %s: final destination */ sprintf(
										__( 'Point to %s', 'wp-redirects' ),
										rule.chain.final
									)
								}
							</Button>
						</>
					) }
				</div>
			</td>
			<td className="adv-redirects-col-status">
				<StatusBadge status={ rule.status_code } />
			</td>
			<td className="adv-redirects-col-hits">
				{ rule.hits.toLocaleString() }
			</td>
			<td className="adv-redirects-col-last">
				<span title={ lastHit ? lastHit.toLocaleString() : undefined }>
					{ timeAgo( rule.last_hit_at ) }
				</span>
			</td>
			<td className="adv-redirects-col-actions">
				<Button
					size="small"
					icon={ pencil }
					label={ __( 'Edit', 'wp-redirects' ) }
					onClick={ () => onEdit( rule.id ) }
				/>
				<Button
					size="small"
					icon={ trash }
					label={ __( 'Delete', 'wp-redirects' ) }
					isDestructive
					onClick={ () => onDelete( rule ) }
				/>
			</td>
		</tr>
	);
}

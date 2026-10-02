import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function ConfirmModal( {
	title,
	message,
	confirmLabel,
	onConfirm,
	onCancel,
} ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ onCancel }
			className="adv-redirects-modal"
		>
			<p>{ message }</p>
			<div className="adv-redirects-modal__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'wp-redirects' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}

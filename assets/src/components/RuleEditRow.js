import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	errorMessage,
	fieldForError,
	isGone,
	STATUS_OPTIONS,
} from '../constants';
import Field from './Field';

export default function RuleEditRow( { rule, colSpan, onSave, onCancel } ) {
	const [ values, setValues ] = useState( {
		source: rule.source,
		target: rule.target || '',
		status_code: String( rule.status_code ),
		note: rule.note || '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const gone = isGone( values.status_code );

	const set = ( key ) => ( value ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setErrors( {} );
		try {
			await onSave( {
				source: values.source.trim(),
				target: gone ? null : values.target.trim(),
				status_code: Number( values.status_code ),
				note: values.note,
			} );
		} catch ( error ) {
			setErrors( { [ fieldForError( error ) ]: errorMessage( error ) } );
			setBusy( false );
		}
	};

	return (
		<tr className="adv-redirects-editrow">
			<td colSpan={ colSpan }>
				{ /* Escape cancels the inline edit from any field in the row. */ }
				{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
				<form
					className="adv-redirects-editrow__form"
					onSubmit={ save }
					onKeyDown={ ( event ) =>
						event.key === 'Escape' && onCancel()
					}
				>
					<Field error={ errors.source }>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Edit source', 'wp-redirects' ) }
							value={ values.source }
							onChange={ set( 'source' ) }
						/>
					</Field>
					{ ! gone && (
						<Field error={ errors.target }>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Edit target', 'wp-redirects' ) }
								value={ values.target }
								onChange={ set( 'target' ) }
							/>
						</Field>
					) }
					<Field error={ errors.status_code }>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Edit status', 'wp-redirects' ) }
							value={ values.status_code }
							options={ STATUS_OPTIONS.map( ( option ) => ( {
								value: String( option.value ),
								label: option.label,
							} ) ) }
							onChange={ set( 'status_code' ) }
						/>
					</Field>
					<Field>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Note', 'wp-redirects' ) }
							value={ values.note }
							onChange={ set( 'note' ) }
							maxLength={ 255 }
						/>
					</Field>
					<div className="adv-redirects-editrow__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ busy }
							__next40pxDefaultSize
						>
							{ __( 'Save', 'wp-redirects' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ onCancel }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'wp-redirects' ) }
						</Button>
					</div>
					{ errors.form && (
						<p className="adv-redirects-field__error" role="alert">
							{ errors.form }
						</p>
					) }
				</form>
			</td>
		</tr>
	);
}

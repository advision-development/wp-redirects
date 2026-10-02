import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	errorMessage,
	fieldForError,
	isGone,
	STATUS_OPTIONS,
} from '../constants';
import { createdLine, editedLine } from '../utils/attribution';
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
	const sourceRef = useRef();
	const gone = isGone( values.status_code );
	const edited = editedLine( rule );

	// Move focus into the row as soon as it opens.
	useEffect( () => {
		sourceRef.current?.focus();
	}, [] );

	const set = ( key ) => ( value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( {
			...current,
			[ key ]: undefined,
			form: undefined,
		} ) );
	};

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

	const onKeyDown = ( event ) => {
		// Escape cancels the edit, except where a native select uses it to
		// close its own list.
		if ( event.key === 'Escape' && event.target.tagName !== 'SELECT' ) {
			event.preventDefault();
			onCancel();
		}
	};

	return (
		<tr
			id={ `adv-redirects-rule-${ rule.id }` }
			className="adv-redirects-editrow"
		>
			<td colSpan={ colSpan }>
				{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
				<form
					className="adv-redirects-editrow__form"
					onSubmit={ save }
					onKeyDown={ onKeyDown }
					aria-label={ sprintf(
						/* translators: %s: redirect source */
						__( 'Edit redirect %s', 'wp-redirects' ),
						rule.source
					) }
				>
					<Field error={ errors.source }>
						{ ( fieldProps ) => (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								{ ...fieldProps }
								ref={ sourceRef }
								label={ __( 'Edit source', 'wp-redirects' ) }
								value={ values.source }
								onChange={ set( 'source' ) }
							/>
						) }
					</Field>
					{ ! gone && (
						<Field error={ errors.target }>
							{ ( fieldProps ) => (
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									{ ...fieldProps }
									label={ __(
										'Edit target',
										'wp-redirects'
									) }
									value={ values.target }
									onChange={ set( 'target' ) }
								/>
							) }
						</Field>
					) }
					<Field error={ errors.status_code } control="select">
						{ ( fieldProps ) => (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								{ ...fieldProps }
								label={ __( 'Edit status', 'wp-redirects' ) }
								value={ values.status_code }
								options={ STATUS_OPTIONS.map( ( option ) => ( {
									value: String( option.value ),
									label: option.label,
								} ) ) }
								onChange={ set( 'status_code' ) }
							/>
						) }
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
					<div className="adv-redirects-meta adv-redirects-editrow__meta">
						<span>{ createdLine( rule ) }</span>
						{ edited && <span>{ edited }</span> }
					</div>
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

import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	errorMessage,
	fieldForError,
	isGone,
	STATUS_OPTIONS,
} from '../constants';
import { notifySaved } from '../utils/notifySaved';
import Field from './Field';

const EMPTY = { type: 'exact', source: '', target: '', status_code: '301' };

export function previewRegex( pattern, path ) {
	try {
		return new RegExp( pattern, 'i' ).test( path )
			? sprintf(
					/* translators: %s: path */
					__( 'Matches "%s" (browser preview)', 'wp-redirects' ),
					path
				)
			: sprintf(
					/* translators: %s: path */
					__(
						'Does not match "%s" (browser preview)',
						'wp-redirects'
					),
					path
				);
	} catch {
		return __(
			'Pattern is not valid in the browser preview.',
			'wp-redirects'
		);
	}
}

function TypeToggle( { value, onChange } ) {
	const options = [
		[ 'exact', __( 'Exact', 'wp-redirects' ) ],
		[ 'regex', __( 'Regex', 'wp-redirects' ) ],
	];
	return (
		<div
			className="adv-redirects-segmented"
			role="group"
			aria-label={ __( 'Match type', 'wp-redirects' ) }
		>
			{ options.map( ( [ option, label ] ) => (
				<Button
					key={ option }
					type="button"
					variant={ value === option ? 'primary' : 'secondary' }
					aria-pressed={ value === option }
					onClick={ () => onChange( option ) }
					__next40pxDefaultSize
				>
					{ label }
				</Button>
			) ) }
		</div>
	);
}

export default function QuickAddForm( {
	onCreate,
	onUpdate,
	notify,
	testPath,
	prefill,
	onPrefillUsed,
} ) {
	const [ values, setValues ] = useState( EMPTY );
	const [ errors, setErrors ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const sourceRef = useRef();

	useEffect( () => {
		if ( prefill ) {
			setValues( { ...EMPTY, source: prefill } );
			setErrors( {} );
			onPrefillUsed();
			sourceRef.current?.focus();
		}
	}, [ prefill, onPrefillUsed ] );

	const set = ( key ) => ( value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( {
			...current,
			[ key ]: undefined,
			form: undefined,
		} ) );
	};

	const gone = isGone( values.status_code );
	const isRegex = values.type === 'regex';
	const sourceHelp =
		isRegex && values.source && testPath
			? previewRegex( values.source, testPath )
			: undefined;

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setErrors( {} );
		try {
			const result = await onCreate( {
				type: values.type,
				source: values.source.trim(),
				target: gone ? null : values.target.trim(),
				status_code: Number( values.status_code ),
			} );
			setValues( ( current ) => ( {
				...EMPTY,
				type: current.type,
				status_code: current.status_code,
			} ) );
			sourceRef.current?.focus();
			notifySaved( result, {
				notify,
				onUpdate,
				message: __( 'Redirect added.', 'wp-redirects' ),
			} );
		} catch ( error ) {
			setErrors( { [ fieldForError( error ) ]: errorMessage( error ) } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form
			className="adv-redirects-quickadd"
			onSubmit={ submit }
			aria-label={ __( 'New redirect', 'wp-redirects' ) }
		>
			<TypeToggle value={ values.type } onChange={ set( 'type' ) } />
			<Field
				error={ errors.source }
				hasHelp={ Boolean( sourceHelp ) }
				className="adv-redirects-quickadd__source"
			>
				{ ( fieldProps ) => (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						{ ...fieldProps }
						ref={ sourceRef }
						label={ __( 'Source', 'wp-redirects' ) }
						placeholder={
							isRegex ? '^/blog/(\\d+)/?$' : '/old-page'
						}
						value={ values.source }
						onChange={ set( 'source' ) }
						help={ sourceHelp }
						required
					/>
				) }
			</Field>
			{ ! gone && (
				<Field
					error={ errors.target }
					className="adv-redirects-quickadd__target"
				>
					{ ( fieldProps ) => (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							{ ...fieldProps }
							label={ __( 'Target', 'wp-redirects' ) }
							placeholder={ isRegex ? '/news/$1' : '/new-page' }
							value={ values.target }
							onChange={ set( 'target' ) }
							required
						/>
					) }
				</Field>
			) }
			<Field
				error={ errors.status_code }
				control="select"
				className="adv-redirects-quickadd__status"
			>
				{ ( fieldProps ) => (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						{ ...fieldProps }
						label={ __( 'Status', 'wp-redirects' ) }
						value={ values.status_code }
						options={ STATUS_OPTIONS.map( ( option ) => ( {
							value: String( option.value ),
							label: option.label,
						} ) ) }
						onChange={ set( 'status_code' ) }
					/>
				) }
			</Field>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
				__next40pxDefaultSize
			>
				{ __( 'Add redirect', 'wp-redirects' ) }
			</Button>
			{ errors.form && (
				<p className="adv-redirects-field__error" role="alert">
					{ errors.form }
				</p>
			) }
		</form>
	);
}

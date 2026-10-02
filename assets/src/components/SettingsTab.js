import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import {
	formatExtensions,
	parseExtensions,
	SETTINGS_FIELDS,
	splitSettingsErrors,
} from '../utils/settings';
import Field from './Field';

export default function SettingsTab( { settings, onSaved, notify } ) {
	const [ values, setValues ] = useState( settings );
	const [ extensions, setExtensions ] = useState(
		formatExtensions( settings.excluded_404_extensions )
	);
	const [ busy, setBusy ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const formRef = useRef();

	useEffect( () => {
		setValues( settings );
		setExtensions( formatExtensions( settings.excluded_404_extensions ) );
	}, [ settings ] );

	const clearError = ( key ) =>
		setErrors( ( current ) => ( { ...current, [ key ]: undefined } ) );

	const set = ( key ) => ( value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		clearError( key );
	};

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setErrors( {} );
		try {
			const saved = await api.saveSettings( {
				slug_watcher: values.slug_watcher,
				forward_query_string: values.forward_query_string,
				log_404: values.log_404,
				remove_data_on_uninstall: values.remove_data_on_uninstall,
				log_404_retention_days: Number( values.log_404_retention_days ),
				log_404_max_rows: Number( values.log_404_max_rows ),
				excluded_404_extensions: parseExtensions( extensions ),
			} );
			onSaved( saved );
			notify( { message: __( 'Settings saved.', 'wp-redirects' ) } );
		} catch ( error ) {
			const { fields, general } = splitSettingsErrors( error );
			setErrors( fields );
			if ( general ) {
				notify( { status: 'error', message: errorMessage( error ) } );
			}
			// Let the user land on the first field that needs fixing.
			const first = SETTINGS_FIELDS.find( ( key ) => fields[ key ] );
			if ( first ) {
				formRef.current
					?.querySelector( `[data-setting="${ first }"]` )
					?.focus();
			}
		} finally {
			setBusy( false );
		}
	};

	return (
		<form
			className="adv-redirects-card adv-redirects-settings"
			onSubmit={ save }
			ref={ formRef }
		>
			<h2 className="adv-redirects-card__title">
				{ __( 'Redirects', 'wp-redirects' ) }
			</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Create a redirect when a published URL changes',
					'wp-redirects'
				) }
				help={ __(
					'Adds a 301 from the old permalink when you change a slug or parent page.',
					'wp-redirects'
				) }
				checked={ values.slug_watcher }
				onChange={ set( 'slug_watcher' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Forward query strings', 'wp-redirects' ) }
				help={ __(
					'Keeps ?utm_source=… and other parameters when redirecting.',
					'wp-redirects'
				) }
				checked={ values.forward_query_string }
				onChange={ set( 'forward_query_string' ) }
			/>

			<h2 className="adv-redirects-card__title">
				{ __( '404 log', 'wp-redirects' ) }
			</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Log 404s', 'wp-redirects' ) }
				checked={ values.log_404 }
				onChange={ set( 'log_404' ) }
			/>
			<div className="adv-redirects-settings__row">
				<Field error={ errors.log_404_retention_days }>
					{ ( fieldProps ) => (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							{ ...fieldProps }
							data-setting="log_404_retention_days"
							type="number"
							min={ 1 }
							max={ 365 }
							label={ __(
								'Keep entries for (days)',
								'wp-redirects'
							) }
							value={ String( values.log_404_retention_days ) }
							onChange={ set( 'log_404_retention_days' ) }
						/>
					) }
				</Field>
				<Field error={ errors.log_404_max_rows }>
					{ ( fieldProps ) => (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							{ ...fieldProps }
							data-setting="log_404_max_rows"
							type="number"
							min={ 100 }
							max={ 100000 }
							label={ __( 'Maximum entries', 'wp-redirects' ) }
							value={ String( values.log_404_max_rows ) }
							onChange={ set( 'log_404_max_rows' ) }
						/>
					) }
				</Field>
			</div>
			<Field error={ errors.excluded_404_extensions } hasHelp>
				{ ( fieldProps ) => (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						{ ...fieldProps }
						data-setting="excluded_404_extensions"
						label={ __(
							'Ignore these file extensions',
							'wp-redirects'
						) }
						help={ __(
							'Comma-separated, for example: css, js, png',
							'wp-redirects'
						) }
						value={ extensions }
						onChange={ ( value ) => {
							setExtensions( value );
							clearError( 'excluded_404_extensions' );
						} }
					/>
				) }
			</Field>

			<h2 className="adv-redirects-card__title">
				{ __( 'Uninstall', 'wp-redirects' ) }
			</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Remove all redirects and settings when the plugin is deleted',
					'wp-redirects'
				) }
				help={ __(
					'Leave off unless you are sure. Deactivating never removes data.',
					'wp-redirects'
				) }
				checked={ values.remove_data_on_uninstall }
				onChange={ set( 'remove_data_on_uninstall' ) }
			/>

			<div>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Save settings', 'wp-redirects' ) }
				</Button>
			</div>
		</form>
	);
}

import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	CheckboxControl,
	ColorIndicator,
	ColorPicker,
	Dropdown,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { normalizeHex } from './utils';

/** @typedef {import('./utils').SettingValue} SettingValue */

const common = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

/**
 * One setting: the control plus its three explanations (what, why, side effects).
 *
 * @param {Object}                        props
 * @param {string}                        props.moduleId
 * @param {Object}                        props.field    field description from PHP (Field::toArray)
 * @param {SettingValue}                  props.value
 * @param {boolean}                       props.locked   set in wp-config.php
 * @param {(value: SettingValue) => void} props.onChange
 */
export default function Field( { moduleId, field, value, locked, onChange } ) {
	const id = `wptb-${ moduleId }-${ field.key }`;
	const Control = CONTROLS[ field.type ];

	return (
		<div
			className="wptb-field"
			data-setting={ `${ moduleId }.${ field.key }` }
		>
			<Control
				id={ id }
				field={ field }
				value={ value }
				disabled={ locked }
				onChange={ onChange }
			/>
			{ locked && (
				<p className="wptb-field__locked">
					{ __(
						"Set in wp-config.php (WPTB_SETTINGS), so it can't be changed here.",
						'wptb'
					) }
				</p>
			) }
			{ field.why && (
				<p className="wptb-field__why">
					<strong>{ __( 'Why:', 'wptb' ) }</strong> { field.why }
				</p>
			) }
			{ field.sideEffects && (
				<p className="wptb-field__side-effects">
					<strong>{ __( 'Keep in mind:', 'wptb' ) }</strong>{ ' ' }
					{ field.sideEffects }
				</p>
			) }
		</div>
	);
}

function BoolControl( { field, value, disabled, onChange } ) {
	return (
		<ToggleControl
			{ ...common }
			label={ field.label }
			help={ field.description }
			checked={ value === true }
			disabled={ disabled }
			onChange={ onChange }
		/>
	);
}

function ChoiceControl( { field, value, disabled, onChange } ) {
	const props = {
		...common,
		label: field.label,
		help: field.description,
		options: field.options,
		disabled,
		onChange,
	};
	return field.options.length <= 4 ? (
		<RadioControl { ...props } selected={ value } />
	) : (
		<SelectControl { ...props } value={ value } />
	);
}

function MultiControl( { id, field, value, disabled, onChange } ) {
	const selected = Array.isArray( value ) ? value : [];
	const toggle = ( option, checked ) =>
		onChange(
			checked
				? [ ...selected, option ]
				: selected.filter( ( v ) => v !== option )
		);

	return (
		<fieldset className="wptb-multi" aria-describedby={ `${ id }-help` }>
			<legend className="wptb-multi__legend">{ field.label }</legend>
			<div
				className={
					field.options.length > 6
						? 'wptb-multi__options is-grid'
						: 'wptb-multi__options'
				}
			>
				{ field.options.map( ( option ) => (
					<CheckboxControl
						{ ...common }
						key={ option.value }
						label={ option.label }
						checked={ selected.includes( option.value ) }
						disabled={ disabled }
						onChange={ ( checked ) =>
							toggle( option.value, checked )
						}
					/>
				) ) }
			</div>
			<p id={ `${ id }-help` } className="components-base-control__help">
				{ field.description }
			</p>
		</fieldset>
	);
}

function IntControl( { field, value, disabled, onChange } ) {
	return (
		<TextControl
			{ ...common }
			type="number"
			label={ field.label }
			help={ field.description }
			value={ value ?? '' }
			min={ field.min ?? undefined }
			max={ field.max ?? undefined }
			disabled={ disabled }
			className="wptb-int"
			// anything that isn't a whole number is kept as typed: the server rejects it with a message
			onChange={ ( raw ) => {
				const number = Number( raw );
				onChange(
					raw !== '' && Number.isInteger( number ) ? number : raw
				);
			} }
		/>
	);
}

function TextFieldControl( { field, value, disabled, onChange } ) {
	return (
		<TextControl
			{ ...common }
			label={ field.label }
			help={ field.description }
			value={ value ?? '' }
			maxLength={ field.maxLength }
			disabled={ disabled }
			onChange={ onChange }
		/>
	);
}

function TextareaFieldControl( { field, value, disabled, onChange } ) {
	return (
		<TextareaControl
			{ ...common }
			label={ field.label }
			help={ field.description }
			value={ value ?? '' }
			maxLength={ field.maxLength }
			rows={ 4 }
			disabled={ disabled }
			onChange={ onChange }
		/>
	);
}

function DateTimeControl( { field, value, disabled, onChange } ) {
	return (
		<div className="wptb-inline">
			<TextControl
				{ ...common }
				type="datetime-local"
				label={ field.label }
				help={ field.description }
				value={ value ?? '' }
				disabled={ disabled }
				onChange={ onChange }
			/>
			{ value && ! disabled && (
				<Button
					{ ...common }
					variant="tertiary"
					onClick={ () => onChange( '' ) }
				>
					{ __( 'Clear', 'wptb' ) }
				</Button>
			) }
		</div>
	);
}

function ColorControl( { id, field, value, disabled, onChange } ) {
	return (
		<BaseControl
			{ ...common }
			id={ id }
			label={ field.label }
			help={ field.description }
		>
			<div className="wptb-inline">
				<Dropdown
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							{ ...common }
							id={ id }
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							disabled={ disabled }
						>
							<ColorIndicator
								colorValue={ value || 'transparent' }
							/>
							<span className="wptb-color__value">
								{ value || __( 'Default', 'wptb' ) }
							</span>
						</Button>
					) }
					renderContent={ () => (
						<ColorPicker
							color={ value || '#ffffff' }
							enableAlpha={ false }
							onChange={ ( color ) =>
								onChange( normalizeHex( color ) )
							}
						/>
					) }
				/>
				{ value && ! disabled && (
					<Button
						{ ...common }
						variant="tertiary"
						onClick={ () => onChange( '' ) }
					>
						{ __( 'Clear', 'wptb' ) }
					</Button>
				) }
			</div>
		</BaseControl>
	);
}

function AttachmentControl( { id, field, value, disabled, onChange } ) {
	// undefined: loading, false: not found
	const [ preview, setPreview ] = useState( undefined );

	useEffect( () => {
		if ( ! value ) {
			setPreview( undefined );
			return;
		}
		let current = true;
		apiFetch( {
			path: `/wp/v2/media/${ value }?_fields=source_url,media_details`,
		} )
			.then( ( media ) => {
				if ( current ) {
					setPreview(
						media.media_details?.sizes?.medium?.source_url ||
							media.source_url
					);
				}
			} )
			.catch( () => current && setPreview( false ) );
		return () => {
			current = false;
		};
	}, [ value ] );

	const choose = () => {
		const frame = window.wp.media( {
			title: field.label,
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Use this image', 'wptb' ) },
		} );
		frame.on( 'select', () =>
			onChange( frame.state().get( 'selection' ).first().get( 'id' ) )
		);
		frame.open();
	};

	return (
		<BaseControl
			{ ...common }
			id={ id }
			label={ field.label }
			help={ field.description }
		>
			<div className="wptb-attachment">
				{ value > 0 && preview && <img src={ preview } alt="" /> }
				{ value > 0 && preview === false && (
					<p>
						{ __( 'The selected image no longer exists.', 'wptb' ) }
					</p>
				) }
				<div className="wptb-inline">
					<Button
						{ ...common }
						id={ id }
						variant="secondary"
						onClick={ choose }
						disabled={ disabled || ! window.wp?.media }
					>
						{ value > 0
							? __( 'Replace image', 'wptb' )
							: __( 'Choose image', 'wptb' ) }
					</Button>
					{ value > 0 && ! disabled && (
						<Button
							{ ...common }
							variant="tertiary"
							isDestructive
							onClick={ () => onChange( 0 ) }
						>
							{ __( 'Remove', 'wptb' ) }
						</Button>
					) }
				</div>
			</div>
		</BaseControl>
	);
}

const CONTROLS = {
	bool: BoolControl,
	choice: ChoiceControl,
	multi: MultiControl,
	int: IntControl,
	text: TextFieldControl,
	textarea: TextareaFieldControl,
	datetime: DateTimeControl,
	color: ColorControl,
	attachment: AttachmentControl,
};

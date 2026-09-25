import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Notice,
	SearchControl,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import Field from './Field';
import {
	changedModules,
	defaultsOf,
	exportPayload,
	matchesSearch,
	mergeValues,
	parseImport,
	sameValue,
	withoutStaleOptions,
} from './utils';

const common = { __next40pxDefaultSize: true };

/**
 * Settings → Toolbox.
 *
 * @param {Object} props
 * @param {Object} props.data window.wptbSettings (SettingsPage::data())
 */
export default function App( { data } ) {
	const { modules, restPath, version } = data;
	const initial = useMemo(
		() => withoutStaleOptions( data.state.values, modules ),
		[ data, modules ]
	);

	const [ saved, setSaved ] = useState( initial );
	const [ draft, setDraft ] = useState( initial );
	const [ locked, setLocked ] = useState( data.state.locked );
	const [ invalidOverrides, setInvalidOverrides ] = useState(
		data.state.invalidOverrides
	);
	const [ active, setActive ] = useState( () => {
		const hash = window.location.hash.slice( 1 );
		return modules.some( ( m ) => m.id === hash ) ? hash : modules[ 0 ].id;
	} );
	const [ query, setQuery ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const fileInput = useRef();

	const changed = useMemo(
		() => changedModules( draft, saved ),
		[ draft, saved ]
	);
	const dirty = changed.length > 0;

	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const warn = ( event ) => event.preventDefault();
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	useEffect( () => {
		window.history.replaceState( null, '', `#${ active }` );
	}, [ active ] );

	const isLocked = ( module, key ) => !! locked[ module ]?.includes( key );
	const setValue = ( module, key, value ) =>
		setDraft( ( d ) => ( {
			...d,
			[ module ]: { ...d[ module ], [ key ]: value },
		} ) );

	async function save() {
		setBusy( true );
		setNotice( null );
		try {
			const state = await apiFetch( {
				path: restPath,
				method: 'POST',
				data: { values: draft },
			} );
			const values = withoutStaleOptions( state.values, modules );
			setSaved( values );
			setDraft( values );
			setLocked( state.locked );
			setInvalidOverrides( state.invalidOverrides );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'wptb' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: sprintf(
					/* translators: %s: error message */
					__( 'The settings were not saved: %s', 'wptb' ),
					error?.message || __( 'unknown error', 'wptb' )
				),
			} );
		} finally {
			setBusy( false );
		}
	}

	function fillIn( incoming ) {
		const result = mergeValues( draft, incoming, modules, locked );
		setDraft( result.values );
		let message = sprintf(
			/* translators: %d: number of settings */
			_n(
				'%d setting filled in from the file.',
				'%d settings filled in from the file.',
				result.applied,
				'wptb'
			),
			result.applied
		);
		message += ' ' + __( 'Review the changes, then save.', 'wptb' );
		if ( result.ignored.length ) {
			message +=
				' ' +
				sprintf(
					/* translators: %s: list of setting names */
					__(
						'Skipped (unknown or set in wp-config.php): %s',
						'wptb'
					),
					result.ignored.join( ', ' )
				);
		}
		setNotice( {
			status: result.ignored.length ? 'warning' : 'info',
			message,
		} );
	}

	function exportFile() {
		const json = JSON.stringify(
			exportPayload( saved, locked, version ),
			null,
			'\t'
		);
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL(
			new Blob( [ json ], { type: 'application/json' } )
		);
		link.download = `wp-toolbox-settings-${ window.location.hostname }-${ new Date().toISOString().slice( 0, 10 ) }.json`;
		// attached and revoked later: browsers cancel downloads of detached links or revoked URLs
		document.body.append( link );
		link.click();
		link.remove();
		setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
	}

	async function importFile( event ) {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		try {
			fillIn( parseImport( await file.text() ) );
		} catch {
			setNotice( {
				status: 'error',
				message: __(
					'This file is not a wp-toolbox settings file.',
					'wptb'
				),
			} );
		}
	}

	const renderFields = ( module, fields ) =>
		fields.map( ( field ) => (
			<Field
				key={ field.key }
				moduleId={ module.id }
				field={ field }
				value={ draft[ module.id ][ field.key ] }
				locked={ isLocked( module.id, field.key ) }
				onChange={ ( value ) =>
					setValue( module.id, field.key, value )
				}
			/>
		) );

	const moduleCard = ( module, fields, withFooter ) => {
		const defaults = defaultsOf(
			module,
			draft[ module.id ],
			locked[ module.id ]
		);
		const isDefault = module.fields.every( ( f ) =>
			sameValue( defaults[ f.key ], draft[ module.id ][ f.key ] )
		);
		return (
			<Card
				key={ module.id }
				className="wptb-module"
				id={ `wptb-module-${ module.id }` }
			>
				<CardHeader>
					<h2>{ module.title }</h2>
				</CardHeader>
				<CardBody>
					<p className="wptb-module__description">
						{ module.description }
					</p>
					{ renderFields( module, fields ) }
				</CardBody>
				{ withFooter && (
					<CardFooter>
						<Button
							{ ...common }
							variant="tertiary"
							disabled={ isDefault }
							onClick={ () =>
								setDraft( ( d ) => ( {
									...d,
									[ module.id ]: defaults,
								} ) )
							}
						>
							{ __(
								'Reset this section to the defaults',
								'wptb'
							) }
						</Button>
					</CardFooter>
				) }
			</Card>
		);
	};

	const results = query.trim()
		? modules
				.map( ( module ) => [
					module,
					module.fields.filter( ( f ) =>
						matchesSearch( module, f, query )
					),
				] )
				.filter( ( [ , fields ] ) => fields.length )
		: null;
	const activeModule = modules.find( ( m ) => m.id === active );

	return (
		<div className="wptb-settings">
			<div className="wptb-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search settings', 'wptb' ) }
					placeholder={ __( 'Search settings', 'wptb' ) }
					value={ query }
					onChange={ setQuery }
				/>
				<div className="wptb-toolbar__actions">
					<Button
						{ ...common }
						variant="tertiary"
						onClick={ exportFile }
					>
						{ __( 'Export', 'wptb' ) }
					</Button>
					<Button
						{ ...common }
						variant="tertiary"
						onClick={ () => fileInput.current.click() }
					>
						{ __( 'Import…', 'wptb' ) }
					</Button>
					<input
						ref={ fileInput }
						type="file"
						accept=".json,application/json"
						hidden
						onChange={ importFile }
					/>
				</div>
			</div>

			{ invalidOverrides.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: list of setting names */
						__(
							"These entries in WPTB_SETTINGS (wp-config.php) are ignored because the setting doesn't exist or the value is invalid: %s",
							'wptb'
						),
						invalidOverrides.join( ', ' )
					) }
				</Notice>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					className="wptb-notice"
				>
					{ notice.message }
				</Notice>
			) }

			<div
				className={ results ? 'wptb-layout is-search' : 'wptb-layout' }
			>
				{ ! results && (
					<nav
						className="wptb-nav"
						aria-label={ __( 'Sections', 'wptb' ) }
					>
						{ modules.map( ( module ) => (
							<Button
								key={ module.id }
								className="wptb-nav__item"
								isPressed={ module.id === active }
								aria-current={
									module.id === active ? 'page' : undefined
								}
								onClick={ () => setActive( module.id ) }
							>
								<span>{ module.title }</span>
								{ changed.includes( module.id ) && (
									<span
										className="wptb-nav__changed"
										title={ __(
											'Unsaved changes',
											'wptb'
										) }
									>
										<span className="screen-reader-text">
											{ __( 'Unsaved changes', 'wptb' ) }
										</span>
									</span>
								) }
							</Button>
						) ) }
					</nav>
				) }
				<div className="wptb-main">
					{ results && results.length === 0 && (
						<p>
							{ __( 'No settings match your search.', 'wptb' ) }
						</p>
					) }
					{ results &&
						results.map( ( [ module, fields ] ) =>
							moduleCard( module, fields, false )
						) }
					{ ! results &&
						moduleCard( activeModule, activeModule.fields, true ) }
				</div>
			</div>

			<div className="wptb-savebar">
				<Button
					{ ...common }
					variant="primary"
					onClick={ save }
					disabled={ ! dirty || busy }
					isBusy={ busy }
				>
					{ __( 'Save changes', 'wptb' ) }
				</Button>
				<Button
					{ ...common }
					variant="tertiary"
					onClick={ () => setDraft( saved ) }
					disabled={ ! dirty || busy }
				>
					{ __( 'Discard changes', 'wptb' ) }
				</Button>
				{ dirty && (
					<span className="wptb-savebar__status">
						{ sprintf(
							/* translators: %s: list of section names */
							__( 'Unsaved changes: %s', 'wptb' ),
							modules
								.filter( ( m ) => changed.includes( m.id ) )
								.map( ( m ) => m.title )
								.join( ', ' )
						) }
					</span>
				) }
			</div>
		</div>
	);
}

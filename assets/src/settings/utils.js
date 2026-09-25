/**
 * Pure helpers of the settings page (no WordPress dependencies, unit tested).
 *
 * Values are always shaped { moduleId: { fieldKey: value } }, locked keys { moduleId: [ fieldKey ] }.
 */

/** @typedef {boolean|number|string|string[]} SettingValue */

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

/**
 * Whether a field matches a search: every word must appear in the field's or its module's texts.
 *
 * @param {Object} module
 * @param {Object} field
 * @param {string} query
 * @return {boolean} match
 */
export function matchesSearch( module, field, query ) {
	const terms = query.toLowerCase().split( /\s+/ ).filter( Boolean );
	const text = [
		module.title,
		field.label,
		field.description,
		field.why,
		field.sideEffects,
	]
		.filter( Boolean )
		.join( ' ' )
		.toLowerCase();
	return terms.every( ( term ) => text.includes( term ) );
}

/**
 * Value equality; lists are compared regardless of order (multi-select values).
 *
 * @param {SettingValue} a
 * @param {SettingValue} b
 * @return {boolean} equal
 */
export function sameValue( a, b ) {
	if ( Array.isArray( a ) && Array.isArray( b ) ) {
		return (
			a.length === b.length &&
			JSON.stringify( [ ...a ].sort() ) ===
				JSON.stringify( [ ...b ].sort() )
		);
	}
	return a === b;
}

/**
 * @param {Object} draft
 * @param {Object} saved
 * @return {string[]} ids of modules whose draft differs from the saved values
 */
export function changedModules( draft, saved ) {
	return Object.keys( draft ).filter( ( module ) =>
		Object.keys( draft[ module ] ).some(
			( key ) =>
				! sameValue( draft[ module ][ key ], saved[ module ]?.[ key ] )
		)
	);
}

/**
 * Merges values (from an import or a preset) into the draft. Unknown and locked settings are skipped;
 * the values themselves are validated by the server on save.
 *
 * @param {Object}   draft
 * @param {unknown}  incoming
 * @param {Object[]} modules
 * @param {Object}   locked
 * @return {{values: Object, applied: number, ignored: string[]}} result
 */
export function mergeValues( draft, incoming, modules, locked ) {
	const values = JSON.parse( JSON.stringify( draft ) );
	const ignored = [];
	let applied = 0;

	for ( const [ moduleId, moduleValues ] of Object.entries( incoming ) ) {
		const module = modules.find( ( m ) => m.id === moduleId );
		if ( ! module || ! isObject( moduleValues ) ) {
			ignored.push( moduleId );
			continue;
		}
		for ( const [ key, value ] of Object.entries( moduleValues ) ) {
			const known = module.fields.some( ( f ) => f.key === key );
			if ( ! known || locked[ moduleId ]?.includes( key ) ) {
				ignored.push( `${ moduleId }.${ key }` );
				continue;
			}
			values[ moduleId ][ key ] = value;
			applied++;
		}
	}

	return { values, applied, ignored };
}

/**
 * Reads an import file: an export of this page ({ plugin, settings }) or a plain settings object.
 *
 * @param {string} text file contents
 * @return {Object} settings
 * @throws {Error} if the file isn't a settings object
 */
export function parseImport( text ) {
	const data = JSON.parse( text );
	const settings =
		isObject( data ) && 'settings' in data ? data.settings : data;
	if ( ! isObject( settings ) ) {
		throw new Error( 'not a settings object' );
	}
	return settings;
}

/**
 * Contents of an export file. Locked values are left out: they belong to wp-config.php.
 *
 * @param {Object} values
 * @param {Object} locked
 * @param {string} version plugin version
 * @return {Object} payload
 */
export function exportPayload( values, locked, version ) {
	const settings = {};
	for ( const [ module, moduleValues ] of Object.entries( values ) ) {
		settings[ module ] = Object.fromEntries(
			Object.entries( moduleValues ).filter(
				( [ key ] ) => ! locked[ module ]?.includes( key )
			)
		);
	}
	return { plugin: 'wp-toolbox', version, settings };
}

/**
 * Colour pickers may return #abc, uppercase or with alpha; settings store #rrggbb.
 *
 * @param {unknown} color
 * @return {string} #rrggbb or ''
 */
export function normalizeHex( color ) {
	if ( typeof color !== 'string' ) {
		return '';
	}
	let hex = color.trim().toLowerCase();
	if ( /^#[0-9a-f]{3}$/.test( hex ) ) {
		hex = '#' + [ ...hex.slice( 1 ) ].map( ( c ) => c + c ).join( '' );
	}
	hex = hex.slice( 0, 7 );
	return /^#[0-9a-f]{6}$/.test( hex ) ? hex : '';
}

/**
 * Saved lists can contain values that are no longer offered (a removed post type or role). The server
 * rejects those on save, so they are dropped from the start.
 *
 * @param {Object}   values
 * @param {Object[]} modules
 * @return {Object} copy of values
 */
export function withoutStaleOptions( values, modules ) {
	const result = JSON.parse( JSON.stringify( values ) );
	for ( const module of modules ) {
		for ( const field of module.fields ) {
			const value = result[ module.id ]?.[ field.key ];
			if ( field.type === 'multi' && Array.isArray( value ) ) {
				const known = field.options.map( ( o ) => o.value );
				result[ module.id ][ field.key ] = value.filter( ( v ) =>
					known.includes( v )
				);
			}
		}
	}
	return result;
}

/**
 * @param {Object}   module
 * @param {Object}   current current values of the module
 * @param {string[]} locked  locked keys of the module
 * @return {Object} default values, locked ones unchanged
 */
export function defaultsOf( module, current, locked = [] ) {
	return Object.fromEntries(
		module.fields.map( ( f ) => [
			f.key,
			locked.includes( f.key ) ? current[ f.key ] : f.default,
		] )
	);
}

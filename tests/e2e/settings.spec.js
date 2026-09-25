// Settings → Toolbox in a real browser against a real WordPress (bin/e2e-site.sh).
const { readFileSync } = require( 'node:fs' );
const { test, expect } = require( '@playwright/test' );

const PAGE = '/wp-admin/options-general.php?page=wp-toolbox';

/** @type {string[]} */
let consoleErrors;

test.beforeEach( async ( { page } ) => {
	consoleErrors = [];
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			consoleErrors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => consoleErrors.push( error.message ) );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
} );

test.afterEach( () => {
	expect( consoleErrors ).toEqual( [] );
} );

async function openSection( page, name ) {
	await page.goto( PAGE );
	await page.getByRole( 'navigation', { name: 'Sections' } ).getByRole( 'button', { name } ).click();
}

const setting = ( page, key ) => page.locator( `[data-setting="${ key }"]` );
// notices are also announced to screen readers, which repeats their text elsewhere in the page
const app = ( page ) => page.locator( '#wptb-settings' );

test( 'shows every section with explanations, locked and ignored wp-config entries', async ( { page } ) => {
	await openSection( page, 'Security' );

	await expect( page.getByRole( 'heading', { name: 'Security', level: 2 } ) ).toBeVisible();
	const xmlrpc = setting( page, 'security.disable_xmlrpc' );
	await expect( xmlrpc.getByRole( 'checkbox' ) ).toBeChecked();
	await expect( xmlrpc.getByRole( 'checkbox' ) ).toBeDisabled();
	await expect( xmlrpc ).toContainText( 'Set in wp-config.php' );
	await expect( xmlrpc ).toContainText( 'Why:' );
	await expect( xmlrpc.getByText( 'Answers every request to xmlrpc.php' ) ).toBeHidden();
	await xmlrpc.getByText( 'How it works' ).click();
	await expect( xmlrpc.getByText( 'Answers every request to xmlrpc.php' ) ).toBeVisible();
	await expect( app( page ).getByText( 'head.nope' ) ).toBeVisible();
	await expect( page.getByRole( 'navigation', { name: 'Sections' } ).getByRole( 'button' ) ).toHaveCount( 10 ); // Elementor is not installed on the test site
} );

test( 'saves a setting, which then takes effect', async ( { page, playwright, baseURL } ) => {
	const visitor = await playwright.request.newContext( { baseURL } );
	expect( ( await visitor.get( '/?rest_route=/wp/v2/comments' ) ).status() ).toBe( 200 );

	await openSection( page, 'Comments' );
	await setting( page, 'comments.disable' ).getByRole( 'checkbox' ).check();
	await expect( app( page ).getByText( 'Unsaved changes: Comments' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();

	await expect( app( page ).getByText( 'Settings saved.' ) ).toBeVisible();
	await page.reload();
	await expect( setting( page, 'comments.disable' ).getByRole( 'checkbox' ) ).toBeChecked();
	expect( ( await visitor.get( '/?rest_route=/wp/v2/comments' ) ).status() ).toBe( 404 );

	// back to the default for the other tests
	await setting( page, 'comments.disable' ).getByRole( 'checkbox' ).uncheck();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( app( page ).getByText( 'Settings saved.' ) ).toBeVisible();
	await visitor.dispose();
} );

test( 'invalid values are rejected with a message and not saved', async ( { page } ) => {
	await page.goto( PAGE );
	// the controls can't produce invalid values, an imported file can
	await page.locator( 'input[type=file]' ).setInputFiles( {
		name: 'settings.json',
		mimeType: 'application/json',
		buffer: Buffer.from( JSON.stringify( { comments: { disable: 'yes' } } ) ),
	} );
	await expect( app( page ).getByText( /filled in from the file/ ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Save changes' } ).click();

	await expect( app( page ).getByText( 'The settings were not saved' ) ).toBeVisible();
	await page.reload();
	await openSectionWithoutReload( page, 'Comments' );
	await expect( setting( page, 'comments.disable' ).getByRole( 'checkbox' ) ).not.toBeChecked();
	consoleErrors = consoleErrors.filter( ( e ) => ! e.includes( '400' ) ); // the rejected request itself
} );

test( 'search finds settings across sections', async ( { page } ) => {
	await page.goto( PAGE );

	await page.getByRole( 'searchbox', { name: 'Search settings' } ).fill( 'openverse' );

	await expect( page.locator( '[data-setting]' ) ).toHaveCount( 1 );
	await expect( setting( page, 'editor.disable_openverse' ) ).toBeVisible();
	await page.getByRole( 'searchbox', { name: 'Search settings' } ).fill( 'nothing matches this' );
	await expect( app( page ).getByText( 'No settings match your search.' ) ).toBeVisible();
} );

test( 'export and import', async ( { page } ) => {
	await page.goto( PAGE );

	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		page.getByRole( 'button', { name: 'Export' } ).click(),
	] );
	const exported = JSON.parse( readFileSync( await download.path(), 'utf8' ) );
	expect( exported.plugin ).toBe( 'wp-toolbox' );
	expect( exported.settings.security ).not.toHaveProperty( 'disable_xmlrpc' ); // locked: belongs to wp-config.php

	exported.settings.comments.disable = true;
	await page.locator( 'input[type=file]' ).setInputFiles( {
		name: 'settings.json',
		mimeType: 'application/json',
		buffer: Buffer.from( JSON.stringify( exported ) ),
	} );

	await expect( app( page ).getByText( /settings filled in from the file/ ) ).toBeVisible();
	await openSectionWithoutReload( page, 'Comments' );
	await expect( setting( page, 'comments.disable' ).getByRole( 'checkbox' ) ).toBeChecked();
	await page.getByRole( 'button', { name: 'Discard changes' } ).click();
} );

async function openSectionWithoutReload( page, name ) {
	await page.getByRole( 'navigation', { name: 'Sections' } ).getByRole( 'button', { name } ).click();
}

test( 'another website cannot change settings with the administrator\'s login (CSRF)', async ( { page } ) => {
	// the browser sends the login cookies, but only the settings page knows the nonce
	const response = await page.request.post( '/?rest_route=/wptb/v1/settings', {
		data: { values: { comments: { disable: true } } },
	} );
	expect( [ 401, 403 ] ).toContain( response.status() );

	await page.goto( PAGE );
	await openSectionWithoutReload( page, 'Comments' );
	await expect( setting( page, 'comments.disable' ).getByRole( 'checkbox' ) ).not.toBeChecked();
} );

test( 'only administrators can open the page', async ( { page, playwright, baseURL } ) => {
	const visitor = await playwright.request.newContext( { baseURL } );
	const response = await visitor.post( '/?rest_route=/wptb/v1/settings', { data: { values: { comments: { disable: true } } } } );
	expect( response.status() ).toBe( 401 );
	await visitor.dispose();

	await page.goto( PAGE );
	await openSectionWithoutReload( page, 'Comments' );
	await expect( setting( page, 'comments.disable' ).getByRole( 'checkbox' ) ).not.toBeChecked();
} );

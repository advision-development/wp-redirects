const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

async function deleteAllRedirects( requestUtils ) {
	const rules = await requestUtils.rest( {
		path: '/adv-redirects/v1/redirects',
	} );
	for ( const rule of rules ) {
		await requestUtils.rest( {
			path: `/adv-redirects/v1/redirects/${ rule.id }`,
			method: 'DELETE',
		} );
	}
}

test.describe( 'Redirects admin', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test( 'adds an exact redirect, tests it, and the site redirects', async ( {
		admin,
		page,
		request,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );

		await page.getByLabel( 'Source', { exact: true } ).fill( '/e2e-old' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/e2e-new' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect(
			page.getByRole( 'cell', { name: '/e2e-old', exact: true } )
		).toBeVisible();

		await page.getByLabel( 'Test a URL' ).fill( '/e2e-old' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await expect(
			page.locator( '.adv-redirects-test__result' )
		).toContainText( '/e2e-new' );

		const response = await request.get( '/e2e-old?utm_source=e2e', {
			maxRedirects: 0,
		} );
		expect( response.status() ).toBe( 301 );
		expect( response.headers().location ).toMatch(
			/\/e2e-new\?utm_source=e2e$/
		);
		expect( response.headers()[ 'x-redirect-by' ] ).toBe( 'WP Redirects' );
	} );

	test( 'shows an inline error when a redirect would loop', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: {
				type: 'exact',
				source: '/loop-a',
				target: '/loop-b',
				status_code: 301,
			},
		} );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByLabel( 'Source', { exact: true } ).fill( '/loop-b' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/loop-a' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect(
			page.getByRole( 'alert' ).filter( { hasText: 'Creates a loop' } )
		).toBeVisible();
	} );

	test( 'a 410 rule returns 410 Gone', async ( {
		requestUtils,
		request,
	} ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: {
				type: 'exact',
				source: '/e2e-gone',
				target: null,
				status_code: 410,
			},
		} );
		const response = await request.get( '/e2e-gone', { maxRedirects: 0 } );
		expect( response.status() ).toBe( 410 );
	} );
} );

/* global describe, it, expect */
import { getSurerankUtmUrl } from './utm';

describe( 'getSurerankUtmUrl', () => {
	it( 'preserves existing query args and fragments when appending UTMs', () => {
		expect(
			getSurerankUtmUrl(
				'https://surerank.com/docs/?ref=plugin#overview',
				'admin_dashboard',
				'help_link'
			)
		).toBe(
			'https://surerank.com/docs/?ref=plugin&utm_source=surerank_plugin&utm_medium=in_product&utm_campaign=admin_dashboard&utm_content=help_link#overview'
		);
	} );

	it( 'returns already tagged links unchanged', () => {
		const url =
			'https://surerank.com/docs/?ref=plugin&utm_campaign=existing#overview';

		expect( getSurerankUtmUrl( url, 'admin_dashboard', 'help_link' ) ).toBe(
			url
		);
	} );

	it( 'returns uploads asset URLs unchanged', () => {
		const url =
			'https://surerank.com/wp-content/uploads/2026/02/example.webp?size=large';

		expect(
			getSurerankUtmUrl( url, 'admin_dashboard', 'asset_preview' )
		).toBe( url );
	} );

	it( 'returns API endpoints unchanged', () => {
		const url = 'https://api.surerank.com/v1/auth?foo=bar';

		expect( getSurerankUtmUrl( url, 'admin_dashboard', 'api_auth' ) ).toBe(
			url
		);
	} );
} );

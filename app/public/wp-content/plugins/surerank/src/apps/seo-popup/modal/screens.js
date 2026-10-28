import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { SettingsScreen } from '@SeoPopup/components';
import { FixItForMe } from '@SeoPopup/components/fix-it-for-me';
import { SchemaAiAuth } from '@SeoPopup/components/schema-ai-auth';

export const SCREENS = applyFilters( 'surerank-pro.seo-popup-screens', {
	settings: {
		title: __( 'Settings', 'surerank' ),
		component: SettingsScreen,
	},
	fixItForMe: {
		title: __( 'Fix It For Me', 'surerank' ),
		component: FixItForMe,
	},
	schemaAuth: {
		title: __( 'Connect SureRank AI', 'surerank' ),
		component: SchemaAiAuth,
	},
} );

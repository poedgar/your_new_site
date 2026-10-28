import PageContentWrapper from '@/apps/admin-components/page-content-wrapper';
import { UpgradeNotice } from '@/global/components/nudges';
import { __ } from '@wordpress/i18n';

/**
 * Role Manager Settings - Pro Feature Placeholder
 * Displays upgrade nudge for the Role Manager feature
 */
const RoleManager = () => {
	return (
		<PageContentWrapper
			title={ __( 'Role Manager', 'surerank' ) }
			description={ __(
				'Manage user roles and their access to SureRank features. Ensure the right people handle the right SEO tasks while protecting critical site settings.',
				'surerank'
			) }
		>
			<UpgradeNotice
				title={ __( 'Unlock Role Manager', 'surerank' ) }
				description={ __(
					'Upgrade to SureRank Pro and manage user roles and their access to SureRank features.',
					'surerank'
				) }
				utmMedium="surerank_role_manager"
			/>
		</PageContentWrapper>
	);
};

export default RoleManager;

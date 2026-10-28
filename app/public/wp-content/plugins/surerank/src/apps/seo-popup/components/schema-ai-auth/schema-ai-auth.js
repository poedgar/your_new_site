import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '@/store/constants';
import {
	AIAuthScreen,
	FixItForMeHeader,
} from '@GlobalComponents/fix-it-for-me';
import { toast } from '@bsf/force-ui';
import { getAuth } from '@/functions/api';
import useAuthPolling from '@/global/hooks/use-auth-polling';
import { LEARN_MORE_AI_AUTH as LEARN_MORE_URL } from '@Global/constants';

/**
 * Connect SureRank AI screen shown when an unconnected user tries to generate
 * schema recommendations. Reuses the shared AIAuthScreen/auth flow; on success
 * (or Back) it returns the user to the Schema tab they came from.
 *
 * @since 1.9.0
 * @return {JSX.Element} The schema AI authentication screen.
 */
const SchemaAiAuth = () => {
	const {
		previousScreen,
		currentScreen,
		previousTab,
		previousMetaTab,
		previousAccordion,
	} = useSelect( ( select ) => {
		const appSettings = select( STORE_NAME ).getAppSettings();
		return {
			previousScreen: appSettings?.previousScreen,
			currentScreen: appSettings?.currentScreen,
			previousTab: appSettings?.previousTab,
			previousMetaTab: appSettings?.previousMetaTab,
			previousAccordion: appSettings?.previousAccordion,
		};
	}, [] );
	const { updateAppSettings, setPageSeoCheck } = useDispatch( STORE_NAME );

	// Return to the screen/tab/accordion the user was on before opening this screen.
	// When `autoRun` is true (after a successful connection) we also flag the Schema
	// tab to re-run the recommendation the user originally triggered.
	const returnToSchema = ( autoRun = false ) => {
		updateAppSettings( {
			currentScreen: previousScreen || 'settings',
			previousScreen: currentScreen,
			currentTab: previousTab || 'optimize',
			currentMetaTab: previousMetaTab || 'optimize',
			currentAccordion: previousAccordion || 'general',
			triggerSchemaRecommend: autoRun,
		} );
	};

	const { openAuthPopup } = useAuthPolling( () => {
		setPageSeoCheck( 'authenticated', true );
		returnToSchema( true );
	} );

	const handleClickLearnMore = () =>
		window.open( LEARN_MORE_URL, '_blank', 'noopener' );

	const handleGetStarted = async () => {
		try {
			const response = await getAuth();
			if ( ! response?.success ) {
				throw new Error(
					response?.message ||
						__( 'Authentication failed', 'surerank' )
				);
			}
			if ( response?.auth_url ) {
				openAuthPopup( response.auth_url );
				return;
			}

			setPageSeoCheck( 'authenticated', true );
			returnToSchema( true );
		} catch ( error ) {
			toast.error(
				error?.message ||
					__( 'An error occurred during authentication', 'surerank' )
			);
		}
	};

	return (
		<div className="p-2 space-y-6">
			<FixItForMeHeader
				className="p-0"
				title={ __( 'Connect SureRank AI', 'surerank' ) }
				onBack={ () => returnToSchema( false ) }
			/>
			<AIAuthScreen
				onClickLearnMore={ handleClickLearnMore }
				onClickGetStarted={ handleGetStarted }
			/>
		</div>
	);
};

export default SchemaAiAuth;

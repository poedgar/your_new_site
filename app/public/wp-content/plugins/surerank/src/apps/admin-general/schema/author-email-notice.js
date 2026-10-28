import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@bsf/force-ui';
import apiFetch from '@wordpress/api-fetch';
import Alert from '@/global/components/alert';

/*
 * The alert is a stretch flex row, so the button grows to the full height of
 * the message and its hover fill then reads as a block rather than a button.
 * Pinning it to the top and dropping that fill keeps it a plain text action,
 * matching the cache compatibility notice, the plugin's other dismissible
 * alert. Padding and the transparent base come from the button itself.
 */
const DISMISS_BUTTON_CLASSES = 'self-start hover:bg-transparent';

/**
 * Explains why the Person schema email field is empty.
 *
 * Older versions filled that field with the post author's account email by
 * default. The upgrade routine clears the saved value, and sets a flag on the
 * sites it actually changed, which is what shows this notice.
 *
 * @return {JSX.Element|null} The notice, or null once dismissed.
 */
const AuthorEmailNotice = () => {
	const [ visible, setVisible ] = useState(
		Boolean( window?.surerank_globals?.author_email_notice )
	);
	const [ dismissing, setDismissing ] = useState( false );

	const handleDismiss = async () => {
		setDismissing( true );

		try {
			await apiFetch( {
				path: '/surerank/v1/author-email-notice/dismiss',
				method: 'POST',
			} );
		} catch ( error ) {
			// The notice is informational, so hiding it locally is enough.
		}

		setVisible( false );
	};

	if ( ! visible ) {
		return null;
	}

	return (
		<Alert
			color="info"
			showIcon
			title={ __( 'A small update to your Person schema', 'surerank' ) }
			message={ __(
				'Person schema used to fill in the post author’s email address by default. It no longer does, so that field is empty. Open the Person schema and add an address if you would like one to show.',
				'surerank'
			) }
			action={
				<Button
					size="xs"
					variant="ghost"
					disabled={ dismissing }
					onClick={ handleDismiss }
					className={ DISMISS_BUTTON_CLASSES }
				>
					{ __( 'Dismiss', 'surerank' ) }
				</Button>
			}
		/>
	);
};

export default AuthorEmailNotice;

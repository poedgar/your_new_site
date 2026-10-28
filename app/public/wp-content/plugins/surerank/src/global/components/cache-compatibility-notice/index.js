import { __ } from '@wordpress/i18n';
import { Button } from '@bsf/force-ui';
import { X } from 'lucide-react';
import Alert from '@/global/components/alert';
import useLocalStorageState from '@/global/hooks/use-local-storage-state';

/**
 * localStorage key that remembers the user dismissed the cache notice.
 * Shared across screens so dismissing once hides it everywhere.
 */
const DISMISS_STORAGE_KEY = 'surerank_cache_notice_dismissed';

/**
 * Cache plugin compatibility notice.
 *
 * Renders a dismissible warning whenever a cache plugin is active on the site,
 * letting users know cached pages may delay SureRank meta/sitemap updates. The
 * caller decides when it is active and passes the flag in; dismissal is stored
 * in localStorage so it stays hidden across reloads and screens.
 *
 * Use `compact` in tight layouts (e.g. the meta box side panel) for a lighter
 * single-line notice with an icon instead of the full title + message block.
 *
 * @since x.x.x
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.active    Whether a cache plugin is active on the site.
 * @param {boolean} [props.compact] Render the lighter icon + one-line variant.
 * @param {string}  [props.title]   Optional title override (ignored when compact).
 * @param {string}  [props.message] Optional message override.
 * @return {JSX.Element|null} The alert, or null when inactive or dismissed.
 */
const CacheCompatibilityNotice = ( {
	active = false,
	compact = false,
	title,
	message,
} ) => {
	const [ dismissed, setDismissed ] = useLocalStorageState(
		DISMISS_STORAGE_KEY,
		false
	);

	if ( ! active || dismissed ) {
		return null;
	}

	const dismissButton = (
		<Button
			variant="ghost"
			size="sm"
			onClick={ () => setDismissed( true ) }
			className="p-1 self-start text-icon-secondary hover:text-icon-primary hover:bg-transparent bg-transparent focus:outline-none"
			icon={ <X /> }
			aria-label={ __( 'Dismiss notice', 'surerank' ) }
		/>
	);

	if ( compact ) {
		return (
			<Alert
				color="warning"
				showIcon
				action={ dismissButton }
				message={
					message ??
					__(
						"A cache plugin may delay when your SEO changes go live. If updates don't appear, purge your cache.",
						'surerank'
					)
				}
			/>
		);
	}

	return (
		<Alert
			color="warning"
			action={ dismissButton }
			title={ title ?? __( 'Cache plugin detected', 'surerank' ) }
			message={
				message ??
				__(
					"A cache plugin is active on your site. It may serve cached pages, so your SEO meta and sitemap updates might not appear right away. If you don't see your latest changes, purge or exclude these from your cache plugin.",
					'surerank'
				)
			}
		/>
	);
};

export default CacheCompatibilityNotice;

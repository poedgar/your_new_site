<?php
/**
 * SEO Admin Bar Toolbar
 *
 * Builds the SureRank SEO menu on the WordPress admin bar: the site-wide audit
 * summary (on every page) plus the current page's check summary, quick tools,
 * and links. Rendered server-side from already-stored checks, so it adds no
 * extra queries beyond reading cached options/meta.
 *
 * @since 1.9.2
 * @package surerank
 */

namespace SureRank\Inc\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Seo_Toolbar
 *
 * @since 1.9.2
 */
class Seo_Toolbar {

	use Get_Instance;

	/**
	 * Parent admin-bar node id (shared with the metabox trigger).
	 */
	private const NODE_ID = 'surerank-meta-box';

	/**
	 * Badge colors by severity.
	 */
	private const STATUS_COLORS = [
		'error'   => '#d63638',
		'warning' => '#dba617',
		'success' => '#00a32a',
	];

	/**
	 * Build the SureRank SEO menu on the admin bar.
	 *
	 * Shows a colored count badge plus a site-audit summary on every page, and
	 * the current page's summary, tools, and editor on a post.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar    Admin bar instance.
	 * @param int           $post_id         Current post id (0 if none).
	 * @param bool          $popup_available Whether the SEO popup editor is enqueued.
	 * @since 1.9.2
	 * @return void
	 */
	public function add_nodes( $wp_admin_bar, $post_id, $popup_available = false ) {
		$site = $this->get_site_counts();
		$page = $post_id > 0 ? $this->get_post_counts( $post_id ) : [];

		/* Parent shows a status dot: the page on a post, the site elsewhere. */
		$badge_counts = $post_id > 0 && ! empty( $page ) ? $page : $site;

		/* On a post the parent opens the metabox; elsewhere it links to the dashboard. */
		if ( $popup_available && $post_id > 0 ) {
			$parent_href = '#';
			$parent_meta = [
				'class'   => 'surerank-meta-box-trigger',
				'title'   => esc_attr__( 'Open SureRank', 'surerank' ),
				'onclick' => 'return false;',
			];
		} else {
			$parent_href = esc_url( admin_url( 'admin.php?page=surerank' ) );
			$parent_meta = [ 'title' => esc_attr__( 'SureRank SEO', 'surerank' ) ];
		}

		$wp_admin_bar->add_node(
			[
				'id'    => self::NODE_ID,
				'title' => $this->parent_title( $badge_counts ),
				'href'  => $parent_href,
				'meta'  => $parent_meta,
			]
		);

		$is_pro   = defined( 'SURERANK_PRO_VERSION' );
		$is_admin = is_admin();

		/* Site-wide checks are a backend-only summary; hide them on the front-end. */
		$show_site = $is_admin && ! empty( $site );

		/* How the page item opens the editor (popup vs edit screen). */
		$page_href    = '#';
		$page_trigger = false;
		if ( $post_id > 0 && ! $popup_available ) {
			$edit      = get_edit_post_link( $post_id, 'url' );
			$page_href = is_string( $edit ) && '' !== $edit ? $edit : admin_url( 'admin.php?page=surerank' );
		} elseif ( $post_id > 0 ) {
			$page_trigger = true;
		}

		/* Status group: site-wide summary (backend only) + the current page. */
		if ( $show_site ) {
			$this->add_summary_node(
				$wp_admin_bar,
				'site',
				__( 'Site SEO Checks', 'surerank' ),
				admin_url( 'admin.php?page=surerank&surerank_src=adminbar_seochecks' ),
				false,
				$site
			);
		}

		/* Manage Page SEO sits in the same group as the site checks, above the separator. */
		if ( $post_id > 0 ) {
			$this->add_edit_node( $wp_admin_bar, self::NODE_ID, $page, $page_href, $page_trigger );
		}

		/* Secondary group so WordPress draws a native separator above the nav links. */
		$rest_parent = self::NODE_ID;
		if ( $show_site || $post_id > 0 ) {
			$rest_parent = self::NODE_ID . '-secondary';
			$wp_admin_bar->add_group(
				[
					'id'     => $rest_parent,
					'parent' => self::NODE_ID,
					'meta'   => [ 'class' => 'ab-sub-secondary' ],
				]
			);
		}

		/* Dashboard + Settings. */
		$this->add_global_nodes( $wp_admin_bar, $is_admin, $rest_parent );

		/* Analyze tools — hidden for now; enable via the filter to restore. */
		if ( $post_id > 0 && apply_filters( 'surerank_admin_bar_show_analyze_tools', false ) ) {
			$this->add_tool_nodes( $wp_admin_bar, $post_id, $rest_parent );
		}

		/* Single Pro entry point at the bottom. */
		if ( ! $is_pro ) {
			$this->add_pro_features_node( $wp_admin_bar, $rest_parent );
		}
	}

	/**
	 * Issue counts for the live admin-bar update (site + page), using the same
	 * logic the bar renders with (ignored checks + static-homepage filter).
	 *
	 * @param int $post_id Current post id (0 if none).
	 * @since 1.9.2
	 * @return array<string, mixed>
	 */
	public function get_status( $post_id ) {
		$site = $this->get_site_counts();
		$page = $post_id > 0 ? $this->get_post_counts( $post_id ) : [];

		return [
			'site' => [
				'error'   => (int) ( $site['error'] ?? 0 ),
				'warning' => (int) ( $site['warning'] ?? 0 ),
			],
			'page' => $post_id > 0 ? [
				'error'   => (int) ( $page['error'] ?? 0 ),
				'warning' => (int) ( $page['warning'] ?? 0 ),
			] : null,
		];
	}

	/**
	 * Tally the site-wide audit checks (option surerank_site_seo_checks).
	 *
	 * @since 1.9.2
	 * @return array<string, int> Status counts, or [] when none stored.
	 */
	private function get_site_counts() {
		$checks = Get::option( 'surerank_site_seo_checks', [] );
		if ( ! is_array( $checks ) || empty( $checks ) ) {
			return [];
		}

		/* Static homepage: its "general" checks are managed in the page editor, so exclude them (matches the dashboard's homepage filter). */
		$skip_general = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0;

		$counts = $this->empty_counts();

		foreach ( $checks as $category_key => $list ) {
			if ( ! is_array( $list ) || ( $skip_general && 'general' === $category_key ) ) {
				continue;
			}
			foreach ( $list as $check ) {
				$this->tally( $counts, $check );
			}
		}

		return $counts;
	}

	/**
	 * Tally the stored per-post checks by status.
	 *
	 * @param int $post_id Post id.
	 * @since 1.9.2
	 * @return array<string, int> Status counts, or [] when no checks.
	 */
	private function get_post_counts( $post_id ) {
		$checks = Get::post_meta( $post_id, SURERANK_SEO_CHECKS, true );
		if ( ! is_array( $checks ) || empty( $checks ) ) {
			return [];
		}

		/* Exclude checks the user has ignored. */
		$ignored = Get::post_meta( $post_id, 'surerank_ignored_post_checks', true );
		$ignored = is_array( $ignored ) ? $ignored : [];

		$counts = $this->empty_counts();

		foreach ( $checks as $id => $check ) {
			if ( in_array( (string) $id, $ignored, true ) ) {
				continue;
			}
			$this->tally( $counts, $check );
		}

		return $counts;
	}

	/**
	 * Fresh counts accumulator.
	 *
	 * @since 1.9.2
	 * @return array<string, int>
	 */
	private function empty_counts() {
		return [
			'error'   => 0,
			'warning' => 0,
			'success' => 0,
		];
	}

	/**
	 * Add a single check to the running counts (skips non-checks and ignored).
	 *
	 * @param array<string, int> $counts Counts accumulator (by reference).
	 * @param mixed              $check  A single check entry.
	 * @since 1.9.2
	 * @return void
	 */
	private function tally( array &$counts, $check ) {
		if ( ! is_array( $check ) || ! isset( $check['status'] ) || ! empty( $check['ignore'] ) ) {
			return;
		}

		$status = (string) $check['status'];
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ]++;
		}
	}

	/**
	 * Build the parent node title: icon, label, and a colored status dot.
	 *
	 * @param array<string, int> $counts Status counts ([] when none).
	 * @since 1.9.2
	 * @return string
	 */
	private function parent_title( $counts ) {
		$icon  = '<span class="ab-icon" style="margin-top: 2px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.5537 1.5C17.8453 1.5 21.3251 4.97895 21.3252 9.27051C21.3252 12.347 19.5368 15.0056 16.9434 16.2646H21.3252V22.5H18.0889C14.9086 22.5 12.2861 20.1186 11.9033 17.042H11.9014L11.9033 13.7852C14.8283 13.7661 17.0342 11.3894 17.0342 8.45996V6.0293C14.137 6.02947 11.6948 7.97682 10.9443 10.6338C10.1605 9.53345 8.87383 8.8165 7.41992 8.81641H6.38086V9.85352H6.38379C6.44515 12.0356 8.23375 13.786 10.4307 13.7861H10.7061L10.6934 17.042H10.6865C10.2943 20.1082 7.67678 22.4785 4.50391 22.4785H2.6748V1.5H13.5537Z" fill="currentColor"/></svg></span>';
		$label = '<span class="ab-label"> SureRank </span>';
		$dot   = '';

		if ( ! empty( $counts ) ) {
			$dot = sprintf(
				'<span class="surerank-ab-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%%;margin-left:6px;vertical-align:middle;background:%s;"></span>',
				esc_attr( $this->dot_color( $counts ) )
			);
		}

		return $icon . $label . $dot;
	}

	/**
	 * Add a summary child node: a status dot + plain label, with the issue
	 * counts shown as a hover tooltip.
	 *
	 * @param \WP_Admin_Bar      $wp_admin_bar Admin bar instance.
	 * @param string             $id           Node id suffix.
	 * @param string             $text         Label text (e.g. "Site SEO Checks").
	 * @param string             $href         Link target ("#" for a trigger).
	 * @param bool               $is_trigger   Whether clicking opens the popup editor.
	 * @param array<string, int> $counts       Status counts for the dot + tooltip.
	 * @since 1.9.2
	 * @return void
	 */
	private function add_summary_node( $wp_admin_bar, $id, $text, $href, $is_trigger, $counts = [] ) {
		$meta = $is_trigger ? [
			'class'   => 'surerank-meta-box-trigger',
			'onclick' => 'return false;',
		] : [];

		$meta['title'] = $this->counts_tooltip( $counts );

		$wp_admin_bar->add_node(
			[
				'id'     => self::NODE_ID . '-' . $id,
				'parent' => self::NODE_ID,
				'title'  => $this->status_dot( $counts ) . esc_html( $text ),
				'href'   => '#' === $href ? '#' : esc_url( $href ),
				'meta'   => $meta,
			]
		);
	}

	/**
	 * Tooltip text for a summary line ("N issues, M warnings" or all-clear).
	 *
	 * @param array<string, int> $counts Status counts.
	 * @since 1.9.2
	 * @return string
	 */
	private function counts_tooltip( $counts ) {
		$errors   = (int) ( $counts['error'] ?? 0 );
		$warnings = (int) ( $counts['warning'] ?? 0 );

		if ( 0 === $errors && 0 === $warnings ) {
			return __( 'All checks passed', 'surerank' );
		}

		/* translators: 1: number of issues, 2: number of warnings. */
		return sprintf( __( '%1$d issues, %2$d warnings', 'surerank' ), $errors, $warnings );
	}

	/**
	 * A colored status dot: red for any errors, amber for warnings, else green.
	 *
	 * @param array<string, int> $counts Status counts.
	 * @since 1.9.2
	 * @return string
	 */
	private function status_dot( $counts ) {
		return sprintf(
			'<span class="surerank-ab-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%%;margin-right:8px;background:%s;"></span>',
			esc_attr( $this->dot_color( $counts ) )
		);
	}

	/**
	 * Severity color for a counts array: red errors, amber warnings, else green.
	 *
	 * @param array<string, int> $counts Status counts.
	 * @since 1.9.2
	 * @return string
	 */
	private function dot_color( $counts ) {
		$errors   = (int) ( $counts['error'] ?? 0 );
		$warnings = (int) ( $counts['warning'] ?? 0 );

		if ( $errors > 0 ) {
			return self::STATUS_COLORS['error'];
		}
		if ( $warnings > 0 ) {
			return self::STATUS_COLORS['warning'];
		}
		return self::STATUS_COLORS['success'];
	}

	/**
	 * "Manage Page SEO" — carries the page status dot + tooltip and opens the
	 * SEO popup editor (or links to the post edit screen).
	 *
	 * @param \WP_Admin_Bar      $wp_admin_bar Admin bar instance.
	 * @param string             $parent       Parent node id.
	 * @param array<string, int> $counts       Page status counts for the dot/tooltip.
	 * @param string             $href         Link target ("#" for a trigger).
	 * @param bool               $is_trigger   Whether clicking opens the popup.
	 * @since 1.9.2
	 * @return void
	 */
	private function add_edit_node( $wp_admin_bar, $parent, $counts, $href, $is_trigger ) {
		$meta = $is_trigger ? [
			'class'   => 'surerank-meta-box-trigger',
			'onclick' => 'return false;',
		] : [];

		$meta['title'] = $this->counts_tooltip( $counts );

		$wp_admin_bar->add_node(
			[
				'id'     => self::NODE_ID . '-edit',
				'parent' => $parent,
				'title'  => $this->status_dot( $counts ) . esc_html__( 'Manage Page SEO', 'surerank' ),
				'href'   => '#' === $href ? '#' : esc_url( $href ),
				'meta'   => $meta,
			]
		);
	}

	/**
	 * Add the quick-tools child nodes (external links for the current URL).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @param int           $post_id      Post id.
	 * @param string        $group_parent Parent node id for the "Analyze" item.
	 * @since 1.9.2
	 * @return void
	 */
	private function add_tool_nodes( $wp_admin_bar, $post_id, $group_parent ) {
		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$encoded = rawurlencode( $url );
		$parent  = self::NODE_ID . '-analyze';

		/* Group the external tools under "Analyze This Page". */
		$wp_admin_bar->add_node(
			[
				'id'     => $parent,
				'parent' => $group_parent,
				'title'  => esc_html__( 'Analyze This Page', 'surerank' ),
				'href'   => '#',
				'meta'   => [ 'onclick' => 'return false;' ],
			]
		);

		$tools = [
			'rich-results' => [
				'label' => __( 'Test with Rich Results', 'surerank' ),
				'url'   => 'https://search.google.com/test/rich-results?url=' . $encoded,
			],
			'pagespeed'    => [
				'label' => __( 'PageSpeed Insights', 'surerank' ),
				'url'   => 'https://pagespeed.web.dev/report?url=' . $encoded,
			],
			'schema'       => [
				'label' => __( 'Validate Schema', 'surerank' ),
				'url'   => 'https://validator.schema.org/?url=' . $encoded,
			],
			'google'       => [
				'label' => __( 'View in Google', 'surerank' ),
				'url'   => 'https://www.google.com/search?q=' . $encoded,
			],
			'facebook'     => [
				'label' => __( 'Facebook Debugger', 'surerank' ),
				'url'   => 'https://developers.facebook.com/tools/debug/?q=' . $encoded,
			],
		];

		foreach ( $tools as $id => $tool ) {
			$wp_admin_bar->add_node(
				[
					'id'     => $parent . '-' . $id,
					'parent' => $parent,
					'title'  => esc_html( $tool['label'] ),
					'href'   => esc_url( $tool['url'] ),
					'meta'   => [
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					],
				]
			);
		}
	}

	/**
	 * Add global links shown on every page (dashboard, settings).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @param bool          $is_admin     Whether we are in wp-admin.
	 * @param string        $parent       Parent node id.
	 * @since 1.9.2
	 * @return void
	 */
	private function add_global_nodes( $wp_admin_bar, $is_admin, $parent ) {
		$links = [
			'dashboard' => [
				'label' => __( 'Dashboard', 'surerank' ),
				'url'   => admin_url( 'admin.php?page=surerank&surerank_src=adminbar_dashboard' ),
			],
		];

		/* Settings is backend-only; keep the front-end bar lean. */
		if ( $is_admin ) {
			$links['settings'] = [
				'label' => __( 'Settings', 'surerank' ),
				'url'   => admin_url( 'admin.php?page=surerank&surerank_src=adminbar_settings#/general' ),
			];
		}

		foreach ( $links as $id => $link ) {
			$wp_admin_bar->add_node(
				[
					'id'     => self::NODE_ID . '-' . $id,
					'parent' => $parent,
					'title'  => esc_html( $link['label'] ),
					'href'   => esc_url( $link['url'] ),
				]
			);
		}
	}

	/**
	 * The single Pro entry point: an "Explore SureRank Premium" link to the SureRank
	 * features page (UTM-tagged), opened in a new tab. Free users only.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @param string        $group_parent Parent node id for the "Explore" item.
	 * @since 1.9.2
	 * @return void
	 */
	private function add_pro_features_node( $wp_admin_bar, $group_parent ) {
		$wp_admin_bar->add_node(
			[
				'id'     => self::NODE_ID . '-explore',
				'parent' => $group_parent,
				'title'  => '<span style="color:#a78bfa;font-weight:600;">' . esc_html__( 'Explore SureRank Premium', 'surerank' ) . '</span>',
				'href'   => esc_url( Helper::get_marketing_link( 'surerank-pro/', 'admin_bar_explore' ) ),
				'meta'   => [
					// Keep the submenu arrow even though there is no dropdown now.
					'class'  => 'menupop',
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				],
			]
		);
	}
}

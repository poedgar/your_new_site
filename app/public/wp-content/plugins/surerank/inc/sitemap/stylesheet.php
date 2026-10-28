<?php
/**
 * Sitemap Stylesheet Class
 *
 * Handles the generation of XML stylesheets for sitemaps.
 *
 * @package SureRank
 * @since 0.0.1
 */

namespace SureRank\Inc\Sitemap;

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Stylesheet
 *
 * Generates sitemap stylesheets dynamically.
 */
class Stylesheet {

	/**
	 * Generates the XML for the sitemap stylesheet.
	 *
	 * @param string $sitemap_title The title of the sitemap.
	 * @param string $sitemap_slug  The slug of the sitemap.
	 * @return string The generated XML stylesheet as a string.
	 */
	public function generate( string $sitemap_title, string $sitemap_slug ): string {
		$crons_available      = Helper::are_crons_available();
		$enable_image_sitemap = Settings::get( 'enable_xml_image_sitemap' );
		$top_bar_data         = $this->get_top_bar_data( $sitemap_title );
		ob_start();
		echo '<?xml version="1.0" encoding="UTF-8"?>'; // Direct echo to avoid PHP parsing issues.
		?>
		<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
			<xsl:output method="html" encoding="UTF-8" indent="yes" />
			<xsl:template match="/">
				<html>
				<head>
					<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
					<title><?php echo esc_html( $sitemap_title ); ?></title>
					<link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,300..900;1,300..900&amp;display=swap" rel="stylesheet" />
					<style>
						body {
							font-family: Figtree, sans-serif;
							background-color: #fff;
							color: #333;
							line-height: 1.6;
							margin: 0;
						}
						h1, h2 {
							color: #fff;
							margin: 0px;
							margin-bottom: 8px;
						}
						p {
							color: #E5E7EB;
							margin: 0px;
							font-size: 14px;
							font-weight: 400;
						}
						table {
							width: 100%;
							table-layout: fixed;
							border-collapse: separate;
							border-spacing: 0;
							background-color: #fff;
							border-radius: 8px;
							box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.05);
							border: 1px solid #E5E7EB;
						}
						th, td {
							padding: 12px;
							text-align: left;

						}
						th {
							background-color: #F9FAFB;
							color: #111827;
							font-weight: 500;
							font-size: 14px;
							border-radius: 8px 8px 0 0;

						}
						td {
							background-color: #fdfdfd;
							color: #4B5563;
							padding: 22px 12px;
							font-size: 14px;
							font-weight: 400;
							border-top: 1px solid #E5E7EB;
							border-radius: 0px 0px 8px 8px;
							word-break: break-word;
						}
						td a {
							color: #4330D2;
							text-decoration: none;
							font-weight: 400;
						}
						a {
							color: #E5E7EB;
							text-decoration: underline;
							font-size: 14px;
							font-weight: 400;
						}
						a:hover {
							text-decoration: underline;
						}
						.date {
							color: #4B5563;
							font-size: 0.9em;
						}
						.image-list {
							font-style: italic;
							color: #4B5563;
						}
						ul {
							list-style-type: none;
							padding: 0;
							margin: 0;
						}
						li {
							margin-bottom: 10px;
						}
						.sitemap-container {
							background-color: <?php echo esc_html( $top_bar_data['background_color'] ); ?>;
							padding: 32px;
						}

						.sitemap-content {
							padding: 16px;
							margin: 0 auto;
							max-width: 1100px;
							margin-top:16px;
						}
						.sitemap-count {
							font-size: 14px;
							font-weight: 400;
							color: #111827;
							padding-bottom: 16px;
						}
						.sitemap-index {
							font-size: 12px;
							font-weight: 600;
							color: #4330D2;
							text-decoration: none;
							padding-bottom: 16px;
						}
						.sitemap-index div {
							margin-bottom: 20px;
						}
					</style>
				</head>
				<body>
					<div class="sitemap-container">
						<h2 style="color: <?php echo esc_html( $top_bar_data['heading_color'] ); ?>;"><?php echo esc_html( $top_bar_data['title'] ); ?></h2>
						<p style="color: <?php echo esc_html( $top_bar_data['description_color'] ); ?>;"><?php echo esc_html( $top_bar_data['description_prefix'] ); ?> <a target="_blank" href="<?php echo esc_url( $top_bar_data['plugin_url'] ); ?>"><?php echo esc_html( $top_bar_data['plugin_label'] ); ?></a>. <?php echo esc_html( $top_bar_data['description_suffix'] ); ?></p>
						<p><a target="_blank" href="<?php echo esc_url( $top_bar_data['learn_more_url'] ); ?>"><?php echo esc_html( $top_bar_data['learn_more_label'] ); ?></a></p>
						<?php if ( ! Generation_Mode::allows_inline_build() && ! $crons_available ) { ?>
							<p>Note: Cron is not enabled on your site. You can use the <a href="<?php echo esc_url( admin_url( 'admin.php?page=surerank#/general/sitemaps' ) ); ?>">Regenerate</a> button to generate the sitemap cache manually.</p>
						<?php } ?>
					</div>
					<div class="sitemap-content">
						<xsl:choose>
							<xsl:when test="s:sitemapindex">
								<p class="sitemap-count" style="font-size: 16px;"> This XML Sitemap Index file contains <b><xsl:value-of select="count(s:sitemapindex/s:sitemap)" />
								<xsl:choose>
									<xsl:when test="count(s:sitemapindex/s:sitemap) = 1"> sitemap</xsl:when>
									<xsl:otherwise> sitemaps</xsl:otherwise>
								</xsl:choose>.</b></p>
								<table>
									<thead>
										<tr>
											<th>Sitemap</th>
											<th>Last Modified</th>
										</tr>
									</thead>
									<tbody>
										<xsl:for-each select="s:sitemapindex/s:sitemap">
											<tr>
												<td>
													<a href="{s:loc}">
														<xsl:value-of select="s:loc" />
													</a>
												</td>
												<td>
													<xsl:value-of select="s:lastmod" />
												</td>
											</tr>
										</xsl:for-each>
									</tbody>
								</table>
							</xsl:when>
							<xsl:when test="count(s:urlset/s:url) = 0">
								<p class="sitemap-count">No Indexable URLs found for this sitemap.</p>
								<a href="<?php echo esc_url( home_url( $sitemap_slug ) ); ?>" class="sitemap-index">
									<div style="display: flex; align-items: center; gap: 8px;">
										<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M11.0846 7H2.91797" stroke="#4330D2" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M7.0013 11.0837L2.91797 7.00033L7.0013 2.91699" stroke="#4330D2" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										Back to Sitemap Index
									</div>
								</a>
							</xsl:when>
							<xsl:otherwise>
								<p class="sitemap-count" style="font-size: 16px;"> This XML Sitemap contains <b><xsl:value-of select="count(s:urlset/s:url)" /></b> URL(s).</p>
								<a href="<?php echo esc_url( home_url( $sitemap_slug ) ); ?>" class="sitemap-index">
									<div style="display: flex; align-items: center; gap: 8px;">
										<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M11.0846 7H2.91797" stroke="#4330D2" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M7.0013 11.0837L2.91797 7.00033L7.0013 2.91699" stroke="#4330D2" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										Back to Sitemap Index
									</div>
								</a>
								<table>
									<thead>
										<tr>
											<th>URL</th>
											<?php if ( $enable_image_sitemap ) { ?>
												<th>Images</th>
											<?php } ?>
											<th>Last Modified</th>
										</tr>
									</thead>
									<tbody>
										<xsl:for-each select="s:urlset/s:url">
											<tr>
												<td>
													<a href="{s:loc}">
														<xsl:value-of select="s:loc" />
													</a>
												</td>
												<?php if ( $enable_image_sitemap ) { ?>
													<td class="image-list">
														<xsl:choose>
															<xsl:when test="image:image">
																<xsl:value-of select="count(image:image)" />
															</xsl:when>
															<xsl:otherwise>0</xsl:otherwise>
														</xsl:choose>
													</td>
												<?php } ?>
												<td class="date">
													<xsl:value-of select="s:lastmod" />
												</td>
											</tr>
										</xsl:for-each>
									</tbody>
								</table>
							</xsl:otherwise>
						</xsl:choose>
					</div>
				</body>
				</html>
			</xsl:template>
		</xsl:stylesheet>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Returns sanitized top bar data for the sitemap stylesheet.
	 *
	 * @param string $sitemap_title Default sitemap title.
	 * @return array<string, string>
	 */
	private function get_top_bar_data( string $sitemap_title ): array {
		$defaults = [
			'title'              => $sitemap_title,
			'description_prefix' => 'This XML Sitemap was generated by the',
			'plugin_label'       => 'SureRank WordPress SEO Plugin',
			'plugin_url'         => Utils::get_utm_url( 'https://surerank.com', 'sitemap', 'sitemap_footer_plugin_link' ),
			'description_suffix' => "It helps search engines like Google, Bing, etc. crawl your website's posts, pages, products, images, and archives.",
			'learn_more_label'   => 'Learn more about XML Sitemaps.',
			'learn_more_url'     => 'https://www.sitemaps.org/protocol.html',
			'background_color'   => '#4330D2',
			'heading_color'      => '#FFFFFF',
			'description_color'  => '#E5E7EB',
		];

		$top_bar_data = apply_filters( 'surerank_sitemap_top_bar_data', $defaults );
		if ( ! is_array( $top_bar_data ) ) {
			$top_bar_data = [];
		}

		$sanitized = array_merge( $defaults, $top_bar_data );

		foreach ( [ 'title', 'description_prefix', 'plugin_label', 'description_suffix', 'learn_more_label' ] as $text_field ) {
			$sanitized[ $text_field ] = is_string( $sanitized[ $text_field ] ) ? wp_strip_all_tags( $sanitized[ $text_field ] ) : $defaults[ $text_field ];
		}

		foreach ( [ 'plugin_url', 'learn_more_url' ] as $url_field ) {
			$sanitized[ $url_field ] = is_string( $sanitized[ $url_field ] ) && '' !== esc_url_raw( $sanitized[ $url_field ] ) ? $sanitized[ $url_field ] : $defaults[ $url_field ];
		}

		foreach ( [ 'background_color', 'heading_color', 'description_color' ] as $color_field ) {
			$sanitized[ $color_field ] = is_string( $sanitized[ $color_field ] ) ? sanitize_hex_color( $sanitized[ $color_field ] ) : '';
			if ( empty( $sanitized[ $color_field ] ) ) {
				$sanitized[ $color_field ] = $defaults[ $color_field ];
			}
		}

		return $sanitized;
	}
}

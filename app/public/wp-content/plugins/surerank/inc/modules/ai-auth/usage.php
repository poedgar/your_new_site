<?php
/**
 * SureRank AI usage helper.
 *
 * Fetches usage data from the credit service and reduces it to a single
 * combined figure (the highest per-feature usage) for the UI.
 *
 * @package SureRank\Inc\Modules\Ai_Auth
 * @since 1.10.1
 */

namespace SureRank\Inc\Modules\Ai_Auth;

use SureRank\Inc\Functions\API_Utils;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Usage
 *
 * Fetches and normalizes SureRank AI usage into a combined figure.
 */
class Usage extends API_Utils {

	use Get_Instance;

	/**
	 * Empty usage payload (used before connection or on failure).
	 *
	 * @since 1.10.1
	 * @return array<string, mixed>
	 */
	public function get_empty_usage() {
		return [
			'percent'  => 0,
			'features' => [],
		];
	}

	/**
	 * Usage payload when the figure is unknown (service unreachable / bad shape).
	 * `null` lets the UI distinguish "no data" from a genuine 0% and hide the bar.
	 *
	 * @since 1.10.1
	 * @return array<string, mixed>
	 */
	public function get_unknown_usage() {
		return [
			'percent'  => null,
			'features' => [],
		];
	}

	/**
	 * Fetch and normalize usage from the credit service.
	 *
	 * Returns a combined percentage plus an optional per-feature breakdown.
	 * Falls back to 0% whenever the service is unreachable or returns an
	 * unexpected shape, so the UI always has something safe to render.
	 *
	 * @since 1.10.1
	 * @return array<string, mixed>
	 */
	public function get_usage() {
		if ( ! Controller::get_instance()->get_auth_status() ) {
			return $this->get_empty_usage();
		}

		/**
		 * Credit-service route that returns SureRank AI usage. Filterable so it
		 * can be pointed at the real endpoint without a code change.
		 *
		 * @since 1.10.1
		 * @param string $route Usage route.
		 */
		$route = (string) apply_filters( 'surerank_ai_usage_route', 'surerank/usage' );

		$response = $this->send_get_request( $route );
		if (
			is_wp_error( $response ) ||
			(int) wp_remote_retrieve_response_code( $response ) >= 400
		) {
			return $this->get_unknown_usage();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->get_unknown_usage();
		}

		return $this->normalize_usage( $data );
	}

	/**
	 * Normalize a usage response into { percent, features }.
	 *
	 * An explicit aggregate always wins, including an explicit 0. Only when
	 * the response carries no aggregate at all is one synthesized as the
	 * highest per-feature percentage: the UI must warn when ANY feature
	 * reaches its limit, and an average would mask an exhausted feature
	 * (100% + 0% must not read as 50%).
	 *
	 * @since 1.10.1
	 * @param array<string, mixed> $usage Raw usage response.
	 * @return array<string, mixed>
	 */
	public function normalize_usage( array $usage ) {
		$data = isset( $usage['data'] ) && is_array( $usage['data'] ) ? $usage['data'] : $usage;

		$features = [];
		$source   = [];
		if ( isset( $data['features'] ) && is_array( $data['features'] ) ) {
			$source = $data['features'];
		} elseif ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$source = $data['usage'];
		}

		foreach ( $source as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$percent          = is_array( $value ) ? $this->read_percent( $value ) : $this->clamp_percent( $value );
			$features[ $key ] = [ 'percent' => $percent ];
		}

		$combined = $this->read_percent_or_null( $data );
		if ( null === $combined && ! empty( $features ) ) {
			$combined = $this->clamp_percent( max( wp_list_pluck( $features, 'percent' ) ) );
		}

		$result = [
			'percent'  => $combined ?? 0,
			'features' => $features,
		];

		/**
		 * Filter the normalized SureRank AI usage payload.
		 *
		 * @since 1.10.1
		 * @param array<string, mixed> $result Normalized usage { percent, features }.
		 * @param array<string, mixed> $usage  Raw usage response.
		 */
		return apply_filters( 'surerank_ai_usage', $result, $usage );
	}

	/**
	 * Read a percentage from a percent field or a used/limit pair.
	 *
	 * @since 1.10.1
	 * @param array<string, mixed> $data Usage data.
	 * @return int|float
	 */
	private function read_percent( array $data ) {
		return $this->read_percent_or_null( $data ) ?? 0;
	}

	/**
	 * Read a percentage, distinguishing "absent" from an explicit 0.
	 *
	 * @since 1.10.1
	 * @param array<string, mixed> $data Usage data.
	 * @return int|float|null Percentage, or null when the data carries neither
	 *                        a numeric percent nor a valid used/limit pair.
	 */
	private function read_percent_or_null( array $data ) {
		if ( isset( $data['percent'] ) && is_numeric( $data['percent'] ) ) {
			return $this->clamp_percent( $data['percent'] );
		}

		$used  = $data['used'] ?? null;
		$limit = $data['limit'] ?? null;
		if ( is_numeric( $used ) && is_numeric( $limit ) && (float) $limit > 0 ) {
			return $this->clamp_percent( (float) $used / (float) $limit * 100 );
		}

		return null;
	}

	/**
	 * Clamp a numeric value to the 0-100 range.
	 *
	 * @since 1.10.1
	 * @param mixed $value Raw value.
	 * @return int|float
	 */
	private function clamp_percent( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		return round( min( 100, max( 0, (float) $value ) ), 2 );
	}
}

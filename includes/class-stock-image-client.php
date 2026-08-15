<?php
/**
 * Openverse stock image search and import.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches Openverse for openly licensed images and imports them
 * into the media library. Import is by Openverse image ID only, so
 * the download URL always comes from the Openverse API — never from
 * client-supplied input (SSRF guard).
 */
class Stock_Image_Client {

	private const API_BASE = 'https://api.openverse.org/v1/images/';

	/**
	 * Searches Openverse images.
	 *
	 * @param string $query Search terms.
	 * @param int    $page  Result page (1-based).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function search( string $query, int $page = 1 ): array|\WP_Error {
		$query = sanitize_text_field( $query );

		if ( '' === trim( $query ) ) {
			return new \WP_Error( 'aibc_stock_missing_query', __( 'A search query is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$url = add_query_arg(
			array(
				'q'            => rawurlencode( $query ),
				'license_type' => 'commercial',
				'page_size'    => 12,
				'page'         => max( 1, $page ),
			),
			self::API_BASE
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aibc_stock_http_error', __( 'Openverse could not be reached.', 'ai-builder-connector' ), array( 'status' => 502 ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
			return new \WP_Error( 'aibc_stock_bad_response', __( 'Openverse returned an unexpected response.', 'ai-builder-connector' ), array( 'status' => 502 ) );
		}

		$results = array();

		foreach ( array_slice( $body['results'], 0, 12 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$results[] = array(
				'id'          => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'thumbnail'   => esc_url_raw( (string) ( $item['thumbnail'] ?? '' ) ),
				'width'       => (int) ( $item['width'] ?? 0 ),
				'height'      => (int) ( $item['height'] ?? 0 ),
				'license'     => sanitize_text_field( (string) ( $item['license'] ?? '' ) ),
				'creator'     => sanitize_text_field( (string) ( $item['creator'] ?? '' ) ),
				'attribution' => sanitize_text_field( (string) ( $item['attribution'] ?? '' ) ),
			);
		}

		return array(
			'query'        => $query,
			'page'         => max( 1, $page ),
			'result_count' => (int) ( $body['result_count'] ?? count( $results ) ),
			'results'      => $results,
			'note'         => __( 'Pass an id to import_stock_image to add it to the media library, then use its attachment url in an image widget.', 'ai-builder-connector' ),
		);
	}

	/**
	 * Imports one Openverse image (by Openverse ID) into the media library.
	 *
	 * @param string $image_id Openverse image UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function import( string $image_id ): array|\WP_Error {
		$image_id = sanitize_text_field( $image_id );

		if ( ! preg_match( '/^[a-f0-9-]{10,64}$/i', $image_id ) ) {
			return new \WP_Error( 'aibc_stock_bad_id', __( 'A valid Openverse image id is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_get( self::API_BASE . rawurlencode( $image_id ) . '/', array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'aibc_stock_not_found', __( 'That Openverse image could not be loaded.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		$item = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$src  = is_array( $item ) ? esc_url_raw( (string) ( $item['url'] ?? '' ) ) : '';

		if ( '' === $src || 0 !== strpos( $src, 'https://' ) ) {
			return new \WP_Error( 'aibc_stock_bad_source', __( 'The image source URL is not usable.', 'ai-builder-connector' ), array( 'status' => 422 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $src, 0, sanitize_text_field( (string) ( $item['title'] ?? 'Stock image' ) ), 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'aibc_stock_import_failed', __( 'The image could not be imported into the media library.', 'ai-builder-connector' ), array( 'status' => 502 ) );
		}

		$attribution = sanitize_text_field( (string) ( $item['attribution'] ?? '' ) );

		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $item['title'] ?? '' ) ) );

		if ( '' !== $attribution ) {
			wp_update_post(
				array(
					'ID'           => (int) $attachment_id,
					'post_excerpt' => $attribution,
				)
			);
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
			'license'       => sanitize_text_field( (string) ( $item['license'] ?? '' ) ),
			'attribution'   => $attribution,
		);
	}
}

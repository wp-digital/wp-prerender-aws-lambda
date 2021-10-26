<?php

namespace Innocode\Prerender;

use Aws\Lambda\LambdaClient;
use WP_Error;
use WP_Http;

/**
 * Class Render
 *
 * @package InnocodeWP\Prerender
 */
class Render
{
    /**
     * AWS Lambda function name
     */
    private const FUNCTION = 'wordpress-prerender';

    /**
     * Element to be rendered via AWS Lambda
     */
    private const ELEMENT = '#app';

    /**
     * Bind post content render with hooks
     */
    public static function register()
    {
        add_action( 'save_post', [ get_called_class(), 'schedule_post_render' ] );
        add_action( 'saved_term', [ get_called_class(), 'schedule_term_render' ], 10, 3 );
        add_action( 'wp_prerender_archive_content', [ get_called_class(), 'archive_render' ], 10, 2 );
        add_action( 'wp_prerender_post_content', [ get_called_class(), 'post_render' ] );
        add_action( 'wp_prerender_term_content', [ get_called_class(), 'term_render' ], 10, 2 );
    }

    /**
     * Schedule to render post/page HTML content
     *
     * @param int $post_id
     */
    public static function schedule_post_render( int $post_id ): void
    {
        if (
            ! in_array( get_post_status( $post_id ), [
                'publish',
                'trash',
            ] ) ||
            wp_is_post_autosave( $post_id ) ||
            wp_is_post_revision( $post_id )
        ) {
            return;
        }

        // Prerender post content
        Post::flush_prerender_meta( $post_id );
        wp_schedule_single_event( time(), 'wp_prerender_post_content', [ $post_id ] );

        // Prerender post archive content
        if( $link = get_post_type_archive_link( $post_type = get_post_type( $post_id ) ) ) {
            if( Archive::is_post_showed_in_archive( $post_id, $post_type ) ) {
                Archive::flush_prerender_option( $post_type );
                wp_schedule_single_event( time(), 'wp_prerender_archive_content', [ $post_type, $link ] );
            }
        }

        // Prerender post terms content
        global $wp_taxonomies;

        foreach( get_post_taxonomies( $post_id ) as $taxonomy ) {
            if( $wp_taxonomies[ $taxonomy ]->public ) {
                $post_terms = get_the_terms( $post_id, $taxonomy );

                foreach( $post_terms as $term ) {
                    if( Term::is_post_showed_in_term( $post_id, $term->term_id ) ) {
                        Term::flush_prerender_meta( $term->term_id );
                        wp_schedule_single_event( time(), 'wp_prerender_term_content', [ $term->term_id, $taxonomy ] );
                    }
                }
            }
        }
    }

    /**
     * Schedule to render term HTML content
     *
     * @param int $term_id
     * @param int $tax_id
     * @param string $taxonomy_slug
     */
    public static function schedule_term_render( int $term_id, int $tax_id, string $taxonomy_slug ): void
    {
        $taxonomy = get_taxonomy( $taxonomy_slug );

        if( $taxonomy && $taxonomy->public ) {
            Term::flush_prerender_meta( $term_id );
            wp_schedule_single_event( time(), 'wp_prerender_term_content', [ $term_id, $taxonomy_slug ] );
        }
    }

    /**
     * Render archive content
     */
    public static function archive_render( string $post_type, string $archive_url ): void
    {
        static::render_with_lambda( [
            'type'          => 'archive',
            'id'            => $post_type,
            'url'           => $archive_url
        ] );
    }

    /**
     * Render archive content
     */
    public static function term_render( int $term_id, string $taxonomy ): void
    {
        static::render_with_lambda( [
            'type'          => 'term',
            'id'            => $term_id,
            'url'           => get_term_link( $term_id, $taxonomy )
        ] );
    }

    /**
     * Render post content
     *
     * @param int $post_id
     */
    public static function post_render( int $post_id ): void
    {
        static::render_with_lambda( [
            'type'          => 'post',
            'id'            => $post_id,
            'url'           => get_permalink( $post_id )
        ] );
    }

    /**
     * Render html markup with AWS Lambda function
     *
     * @param array $args
     */
    public static function render_with_lambda( array $args ): void
    {
        static::run_lambda(
            static::get_lambda_client(),
            wp_parse_args( $args, [
                'return_url'    => Rest::get_return_url(),
                'secret'        => Security::get_secret_hash(),
                'element'       => apply_filters( 'wp_prerender_element', static::ELEMENT )
            ] )
        );
    }

    /**
     * Return AWS Lambda client
     *
     * @return LambdaClient
     */
    private static function get_lambda_client(): LambdaClient
    {
        return new LambdaClient( [
            'credentials' => [
                'key'    => defined( 'AWS_LAMBDA_WP_PRERENDER_KEY' ) ? AWS_LAMBDA_WP_PRERENDER_KEY : '',
                'secret' => defined( 'AWS_LAMBDA_WP_PRERENDER_SECRET' ) ? AWS_LAMBDA_WP_PRERENDER_SECRET : '',
            ],
            'region'      => defined( 'AWS_LAMBDA_WP_PRERENDER_REGION' ) ? AWS_LAMBDA_WP_PRERENDER_REGION : '',
            'version'     => 'latest',
        ] );
    }

    /**
     * Returns lambda function name
     *
     * @return string
     */
    private static function get_lambda_function_name(): string
    {
        return defined( 'AWS_LAMBDA_WP_PRERENDER_FUNCTION' )
            ? AWS_LAMBDA_WP_PRERENDER_FUNCTION
            : static::FUNCTION;
    }

    /**
     * Invoke AWS Lambda function
     *
     * @param LambdaClient $client
     * @param array $args
     *
     * @return bool|WP_Error
     */
    private static function run_lambda( LambdaClient $client, array $args )
    {
        $result = $client->invoke( [
            'FunctionName'   => static::get_lambda_function_name(),
            'Payload'        => json_encode( $args ),
            'InvocationType' => 'Event'
        ] );

        return $result[ 'StatusCode' ] != WP_Http::ACCEPTED
            ? new WP_Error( static::FUNCTION, $result[ 'FunctionError' ] )
            : true;
    }
}

<?php

namespace Innocode\Prerender\Integrations\Batcache;

use Innocode\Prerender\Entry;
use Innocode\Prerender\Helpers;
use Innocode\Prerender\Interfaces\IntegrationInterface;
use Innocode\Prerender\Plugin;

class Integration implements IntegrationInterface
{
    /**
     * @var Plugin
     */
    protected $plugin;

    /**
     * @return Plugin
     */
    public function get_plugin() : Plugin
    {
        return $this->plugin;
    }

    /**
     * @inheritDoc
     */
    public function run( Plugin $plugin ) : void
    {
        $this->plugin = $plugin;

        Helpers::hook( 'innocode_prerender_callback', [ $this, 'flush' ] );
    }

    /**
     * @param Entry  $entry
     * @param string $template_name
     * @param string $id
     * @return void
     */
    public function flush( Entry $entry, string $template_name, string $id ) : void
    {
        if (
            ! function_exists( 'batcache_clear_url' ) ||
            null === ( $template = $this->get_plugin()->find_template( $template_name ) ) ||
            null === ( $url = $template->get_link( $id ) )
        ) {
            return;
        }

        batcache_clear_url( $url );
    }
}

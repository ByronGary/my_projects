<?php

namespace Drupal\Pdf_print\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Class ThemeNegotiator
 *
 * @package Drupal\Pdf_print\Theme
 */
class ThemeNegotiator implements ThemeNegotiatorInterface
{

    /**
     * The config factory service
     *
     * @var ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * ThemeNegotiator constructor.
     *
     * @param ConfigFactoryInterface $config_factory
     */
    public function __construct(ConfigFactoryInterface $config_factory)
    {
        $this->configFactory = $config_factory;
    }

    /**
     * {@inheritdoc}
     */
    public function applies(RouteMatchInterface $route_match)
    {
        $route_name = $route_match->getRouteName();

        $is_entity_print_route = in_array($route_name, [
            'entity_print.view',
            'entity_print.view.debug',
            'system.batch_page.html',
            'system.batch_page.json']);
        if ($is_entity_print_route) {
            return TRUE;
        }

    }

    /**
     * {@inheritdoc}
     */
    public function determineActiveTheme(RouteMatchInterface $route_match)
    {
        return 'THEME_NAME';
    }

}

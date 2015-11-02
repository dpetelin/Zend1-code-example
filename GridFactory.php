<?php

namespace ISSArt\Bundle\DataGridBundle\Grid;

use Kitpages\DataGridBundle\Grid\GridConfig;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GridFactory implements GridFactoryInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Factory constructor
     *
     * @param ContainerInterface $container Service container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Grid factory method
     *
     * @param string|GridConfig    $gridConfig  Grid config instance, container service id or class name
     * @param Request              $request     Request
     *
     * @return Grid
     */
    public function create($gridConfig, Request $request = null)
    {
        if (! (is_string($gridConfig) || $gridConfig instanceof GridConfig)) {
            throw new \InvalidArgumentException(
                'Attribute $gridConfig should be string or instance of GridConfigInterface'
            );
        }

        if (is_string($gridConfig)) {
            $gridConfig = $this->container->has($gridConfig)
                ? $this->container->get($gridConfig)
                : new $gridConfig;
        }

        if (! $request instanceof Request) {
            $request = $this->container->get('request');
        }

        if ($gridConfig instanceof ContainerAwareInterface) {
            $gridConfig->setContainer($this->container);
        }

        if ($gridConfig instanceof InitializedGridConfigInterface) {
            $gridConfig->initialize();
        }

        return $this
            ->container
            ->get('kitpages_data_grid.grid_manager')
            ->getGrid($gridConfig, $request);
    }
}

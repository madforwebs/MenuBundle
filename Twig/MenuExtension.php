<?php

/*
 * This file is part of the MadForWebs package
 *
 * Copyright (c) 2017 Fernando Sánchez Martínez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Fernando Sánchez <fer@madforwebs.com>
 */

namespace MadForWebs\MenuBundle\Twig;

use MadForWebs\MenuBundle\Menu\CurrentRouterAwareInterface;
use MadForWebs\MenuBundle\Menu\MenuInterface;
use MadForWebs\MenuBundle\Menu\RequestAwareInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class MenuExtension extends \Twig_Extension
{
    /** @var ContainerInterface */
    private $container;

    /** @var \Twig_Environment */
    private $twig;

    /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
    private $session;

    /** @var Router */
    private $router;

    /** @var Request $request */
    private $request = false;

    /** @var string */
    private $route = false;

    /** @var bool */
    private $selected = false;

    /**
     * MenuExtension constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->twig = $container->get('twig');
        $this->router = $container->get('router');
        $this->request = $container->get('request_stack')->getCurrentRequest();
        if ($this->request) {
            $this->route = $this->request->get('_route');
        }
        $this->session = $container->get('session');
    }

    /**
     * @return array
     */
    public function getFunctions()
    {
        return [new \Twig_SimpleFunction('renderMenu', [$this, 'render'], ['is_safe' => ['html']]),];
    }

    /**
     * @param string $serviceOrClassName
     * @param string $template
     * @param array  $parameters
     *
     * @return string
     */
    public function render($serviceOrClassName, $template = 'sidebar',  $parameters = [] )
    {
        $parameters = array();
        if ($this->container->has($serviceOrClassName)) {
            $builder = $this->container->get($serviceOrClassName);
        } else {
            if (!class_exists($serviceOrClassName)) {
                throw new \InvalidArgumentException(sprintf('Class "%s" not exist', $serviceOrClassName));
            }

            $builder = new $serviceOrClassName();
        }

        if (!$builder instanceof MenuInterface) {
            throw new \InvalidArgumentException(sprintf('Class "%s" not implements MenuInterface', $serviceOrClassName));
        }

        $menu = $builder->getMenu($this->session);
        $menu = $this->prepareMenu($menu, $parameters);
        if ($this->twig->getLoader()->exists(sprintf('MenuBundle:Menu:%s.html.twig', $template))) {
            return $this->twig->render(sprintf('MenuBundle:Menu:%s.html.twig', $template), ['menu' => $menu]);
        }

        return $this->twig->render($template, ['menu' => $menu]);
    }

    /**
     * @param string $serviceOrClassName
     * @return MenuInterface
     */
    protected function getService(string $serviceOrClassName)
    {
        if ($this->container->has($serviceOrClassName)) {
            $builder = $this->container->get($serviceOrClassName);
        } else {
            if (!class_exists($serviceOrClassName)) {
                throw new \InvalidArgumentException(sprintf('class or service "%s" not exist', $serviceOrClassName));
            }

            $builder = new $serviceOrClassName();
        }

        if ($builder instanceof ContainerAwareInterface && $this->container) {
            $builder->setContainer($this->container);
        }

        if ($builder instanceof RequestAwareInterface && $this->request) {
            $builder->setRequest($this->request);
        }

        return $builder;

    }

    /**
     * @param array $item
     *
     * @return array
     */
    protected function prepareItem( $item,  $parameters = [])
    {
        $required = [
            'active',
            'anchor_attr',
            'anchor_class',
            'attr',
            'class',
            'credentials',
            'icon',
            'items',
            'name',
            'route',
            'route_parameters',
        ];
        foreach ($required as $r) {
            if (!array_key_exists($r, $item)) {
                $item[$r] = false;
            }
        }

        $arrays = ['active', 'anchor_attr', 'attr', 'items', 'route_parameters',];
        foreach ($arrays as $a) {
            if (!is_array($item[$a])) {
                $item[$a] = [];
            }
        }

        $parameters = array_merge($item['route_parameters'], $parameters);

        foreach ($item['items'] as $key => $value) {
            $item['items'][$key] = $this->prepareItem($value, $parameters);
            if ($item['items'][$key]['selected']) {
                $item['selected'] = true;
            }
        }

        $item['link'] = '#';
        if ($item['route']) {
            $item['link'] = $this->router->generate($item['route'], $parameters);
        }

        if (array_key_exists('selected', $item)) {
            if ($item['selected']) {
                return $item;
            }
        }

        $item['selected'] = $this->isSelected($item);

        return $item;
    }

    /**
     * @param array $menu
     * @param array $parameters
     *
     * @return array
     */
    protected function prepareMenu( $menu,  $parameters = [])
    {
        $this->selected = false;
        $required = ['attr', 'class', 'items', 'use_span',];
        foreach ($required as $r) {
            if (!isset($menu[$r])) {
                $menu[$r] = false;
            }
        }

        $arrays = ['attr', 'items'];
        foreach ($arrays as $a) {
            if (!is_array($menu[$a])) {
                $menu[$a] = [];
            }
        }

        foreach ($menu['items'] as $i => $j) {
            $menu['items'][$i] = $this->prepareItem($j, $parameters);
            if (count($menu['items'][$i]['items'])) {
                foreach ($menu['items'][$i]['items'] as $x => $y) {
                    $menu['items'][$i]['items'][$x] = $this->prepareItem($y, $parameters);
                    if ($menu['items'][$i]['items'][$x]['selected']) {
                        $menu['items'][$i]['selected'] = true;
                    }
                }
            }
        }

        return $menu;
    }

    /**
     * @param array $item
     *
     * @return bool
     */
    private function isSelected( $item)
    {
        if ($this->selected) {
            return false;
        }

        if ($this->route == $item['route']) {
            $this->selected = true;

            return true;
        }

        if (in_array($this->route, $item['active'])) {
            $this->selected = true;

            return true;
        }

        foreach ($item['active'] as $active) {
            if (preg_match(sprintf('#%s#', $active), $this->route) === 1) {
                $this->selected = true;

                return true;
            }
        }

        return false;
    }
}

<?php

abstract class Shopware_Plugins_Frontend_Boxalino_Interceptor
{
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_Bootstrap
     */
    private $bootstrap;

    /**
     * @var Shopware_Plugins_Frontend_Boxalino_Bootstrap
     */
    private $config;

    /**
     * @var Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    private $helper;

    /**
     * @var Shopware_Controllers_Frontend_Index
     */
    private $controller;

    /**
     * @var Enlight_Controller_Request_Request
     */
    private $request;

    /**
     * @var Enlight_View_Default
     */
    private $view;

    /**
     * constructor
     * @param Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap
     */
    public function __construct(Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap)
    {
        $this->helper = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance();
        $this->bootstrap = $bootstrap;
        $this->config = Shopware()->Config();
    }

    /**
     * Initialize important variables
     * @param Enlight_Event_EventArgs $arguments
     */
    protected function init(Enlight_Event_EventArgs $arguments)
    {
        $this->controller = $arguments->getSubject();
        $this->request = $this->controller->Request();
        $this->view = $this->controller->View();
        $this->helper->setRequest($this->request);
    }

    /**
     * Returns bootstrap instance
     *
     * @return Shopware_Plugins_Frontend_Boxalino_Bootstrap
     */
    protected function Bootstrap()
    {
        return $this->bootstrap;
    }

    /**
     * Returns config instance
     *
     * @return Shopware_Components_Config
     */
    protected function Config()
    {
        return $this->config;
    }

    /**
     * Returns helper instance
     *
     * @return Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    protected function Helper()
    {
        return $this->helper;
    }

    /**
     * Returns controller instance
     *
     * @return Shopware_Controllers_Frontend_Index
     */
    protected function Controller()
    {
        return $this->controller;
    }

    /**
     * Returns request instance
     *
     * @return Enlight_Controller_Request_Request
     */
    protected function Request()
    {
        return $this->request;
    }

    /**
     * Returns view instance
     *
     * @return Enlight_View_Default
     */
    protected function View()
    {
        return $this->view;
    }
}
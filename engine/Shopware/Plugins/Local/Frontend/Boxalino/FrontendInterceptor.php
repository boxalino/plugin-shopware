<?php


class Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor
{
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    private $helper;
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_Bootstrap
     */
    private $bootstrap;

    function __construct(Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap)
    {
        $this->helper = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance();
        $this->bootstrap = $bootstrap;
    }

    public function intercept(Enlight_Event_EventArgs $arguments)
    {
        /** @var $controller Shopware_Controllers_Frontend_Index */
        $controller = $arguments->getSubject();

        /** @var $request Zend_Controller_Request_Http */
        $request = $controller->Request();

        $controllerName = $request->getParam('controller');
        $actionName = $request->getParam('action');
        $view = $controller->View();

        $script = null;
        if ($controllerName == 'detail') {
            // Replace similar products
            $term = trim(strip_tags(htmlspecialchars_decode(stripslashes($request->sArticle))));
            if(Shopware()->Config()->get('boxalino_product_recommendation_enabled')) {
                if (empty($actionName)) {
                    $sArticle = $view->sArticle;
                    $similarArticles = $this->helper->findRecommendations(
                        $term,
                        'mainProduct',
                        Shopware()->Config()->get('boxalino_recommendation_widget_name')
                    );
                    $sArticle['sSimilarArticles'] = $similarArticles;
                    $view->assign('sArticle', $sArticle);
                }
            }
            $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportProductView($term);

        } else if ($controllerName == 'search') {
            $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportSearch($request);

        } else if ($controllerName == 'cat') {
            $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportCategoryView($request->sCategory);
        } else if (($controllerName == 'checkout' || $controllerName == 'account') && $_SESSION['Shopware']['sUserId'] != null) {
            $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportLogin($_SESSION['Shopware']['sUserId']);
        }else {
            $param = $request->getParam('callback');
            if (empty($param)) // skip for ajax calls
                $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportPageView();
        }

        if ($script != null && Shopware()->Config()->get('boxalino_tracking_enabled')) {
            $view->addTemplateDir($this->bootstrap->Path() . 'Views/');
            $view->extendsTemplate('frontend/index.tpl');
            $view->assign('report_script', $script);
        }
    }
}
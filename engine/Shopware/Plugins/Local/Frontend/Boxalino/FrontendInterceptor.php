<?php

/**
 * frontend interceptor
 */
class Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor
    extends Shopware_Plugins_Frontend_Boxalino_Interceptor
{
    public function intercept(Enlight_Event_EventArgs $arguments)
    {
        $this->init($arguments);

        $script = null;
        switch ($this->Request()->getParam('controller')) {
            case 'detail':
                $id = trim(strip_tags(htmlspecialchars_decode(stripslashes($this->Request()->sArticle))));
                if($this->Config()->get('boxalino_product_recommendation_enabled')) {
                    // Replace similar & related products, if choice IDs given
                    $sArticle = $this->View()->sArticle;
                    foreach (array(
                        'sSimilarArticles' => 'boxalino_recommendation_widget_name',
                        'sRelatedArticles' => 'boxalino_recommendation_related_widget_name',
                    ) as $articleKey => $configOption) {
                        $choiceId = $this->Config()->get($configOption);
                        if (strlen($choiceId)) {
                            $articles = $this->Helper()->findRecommendations(
                                $id,
                                'mainProduct',
                                $choiceId
                            );
                            $sArticle[$articleKey] = $articles;
                        }
                    }
                    $this->View()->assign('sArticle', $sArticle);
                }
                $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportProductView($id);
                break;
            case 'search':
                $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportSearch($this->Request());
                break;
            case 'cat':
                $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportCategoryView($this->Request()->sCategory);
                break;
            case 'checkout':
            case 'account':
                if ($_SESSION['Shopware']['sUserId'] != null) {
                    $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportLogin($_SESSION['Shopware']['sUserId']);
                }
            default:
                $param = $this->Request()->getParam('callback');
                // skip ajax calls
                if (empty($param)) {
                    $script = Shopware_Plugins_Frontend_Boxalino_EventReporter::reportPageView();
                }
        }

        if ($script != null && $this->Config()->get('boxalino_tracking_enabled')) {
            $this->View()->addTemplateDir($this->Bootstrap()->Path() . 'Views/');
            $this->View()->extendsTemplate('frontend/index.tpl');
            $this->View()->assign('report_script', $script);
        }
    }
}
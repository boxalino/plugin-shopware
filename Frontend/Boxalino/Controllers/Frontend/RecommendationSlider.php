<?php
class Shopware_Controllers_Frontend_RecommendationSlider extends Enlight_Controller_Action {

    public function indexAction() {
        $this->productStreamSliderRecommendationsAction();
    }
    
    public function productStreamSliderRecommendationsAction() {
        $helper = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance();
        $choiceId = $this->Request()->getQuery('bxChoiceId');
        $count = $this->Request()->getQuery('bxCount');
        $articles = $helper->findRecommendations(null, null, $choiceId, $count);
        $this->View()->assign('articles', $articles);
    }

}
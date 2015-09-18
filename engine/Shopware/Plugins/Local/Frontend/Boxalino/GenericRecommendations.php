<?php

class Shopware_Plugins_Frontend_Boxalino_GenericRecommendations
{
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    private $helper;

    /**
     * constructor
     */
    public function __construct()
    {
        $this->helper = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance();
    }

    /**
     * Request article recommendations for one or multiple choiceIds
     *
     * If one $choiceId is given, the result will be an array with the keys
     * 'results' and 'count'. For multiple $choiceId, the result is an array
     * with the choiceIds as the first dimension and 'results' and 'count' as
     * the second one.
     *
     * For generic recommendations, no special context is necessary. If you
     * display recommendations associated to products (i.e. on category or
     * basket pages) you should send along the articles detail id as 'contextItem'.
     * Boxalino might request you to send additional context parameters in special
     * cases.
     *
     * @param string|array $choiceId one choiceId of an array of multiple choiceIds
     * @param int $amount of products to recommend, defaults to 5
     * @param array $context parameters to add to the request, i.e. array('contextItem' => 123), where 123 is the article detail id
     * @return array
     */
    public function getArticlesForChoice($choiceId, $amount = 5, $context = array())
    {
        $choiceIds = is_array($choiceId) ? $choiceId : array($choiceId);
        if (array_key_exists('contextItem', $context)) {
            $id = $context['contextItem'];
            unset($context['contextItem']);
        } else {
            $id = 0;
        }
        $result = $this->helper->findRecommendations(
            $id, 'mainProduct', $choiceIds, $amount, $context
        );
        if (is_array($choiceId)) return $result;
        return $result[$choiceId];
    }
}
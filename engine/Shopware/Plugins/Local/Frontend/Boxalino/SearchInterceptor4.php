<?php

/**
 * search interceptor for shopware 4
 * uses smarty variables and request context
 */
class Shopware_Plugins_Frontend_Boxalino_SearchInterceptor4
    extends Shopware_Plugins_Frontend_Boxalino_SearchInterceptor
{
    private $categories;
    private $config;

    /**
     * Price filters to display in frontend
     * @var array
     */
    private $configPriceFilter = array(
        1  => array('start' => 0,    'end' => 5   ),
        2  => array('start' => 5,    'end' => 10  ),
        3  => array('start' => 10,   'end' => 20  ),
        4  => array('start' => 20,   'end' => 50  ),
        5  => array('start' => 50,   'end' => 100 ),
        6  => array('start' => 100,  'end' => 300 ),
        7  => array('start' => 300,  'end' => 600 ),
        8  => array('start' => 600,  'end' => 1000),
        9  => array('start' => 1000, 'end' => 1500),
        10 => array('start' => 1500, 'end' => 2500),
        11 => array('start' => 2500, 'end' => 3500),
        12 => array('start' => 3500, 'end' => 5000),
    );

    /**
     * perform search
     * @param Enlight_Event_EventArgs $arguments
     * @return boolean
     */
    public function search(Enlight_Event_EventArgs $arguments)
    {
        if (!Shopware()->Config()->get('boxalino_search_enabled')) {
            return false;
        }
        $this->init($arguments);

        $term = $this->getSearchTerm();

        // Check if we have a one to one match for ordernumber, then redirect
        $location = $this->searchFuzzyCheck($term);
        if (!empty($location)) {
            return $this->controller->redirect($location);
        }

        $this->View()->loadTemplate('frontend/search/fuzzy.tpl');

        // Check if search term met minimum length
        if (strlen($term) >= (int) Shopware()->Config()->sMINSEARCHLENGHT) {

            // Load search configuration
            $this->prepareSearchConfiguration($term);

            $config = $this->get('config');
            $pageCounts = array_values(explode('|', $config->get('fuzzySearchSelectPerPage')));
            $pageCount = ($this->config['currentPage']) * $this->config['resultsPerPage'];
            $pageOffset = ($this->config['currentPage'] - 1) * $this->config['resultsPerPage'];

            $this->prepareCategoriesTree();
            $currentCategoryFilter = $this->config['filter']['category'] ? $this->config['filter']['category'] : null;
            $choice = $this->helper->search(
                $term, $pageOffset, $pageCount, array(
                    'category' => $currentCategoryFilter,
                    'categoryName' => $array($this->categories[$currentCategoryFilter]['description']),
                    'price' => $this->getCurrentPriceRange(),
                    'supplier' => !empty($this->config['filter']['supplier']) ? $this->config['filter']['supplier'] : null,
                    'sort' => $this->getSortOrder4()
                )
            );
            $results = $this->helper->extractResults($choice);
            $suppliersFacets = $this->helper->extractFacet($choice, 'products_supplier');
            $priceFacets = $this->helper->extractFacet($choice, 'discountedPrice');
            $categoryFacets = $this->helper->extractFacet($choice, 'categories');
            $ids = $this->helper->extractFacet($choice, 'id');

            // Initiate variables
            $resultCount = 0;
            $resultArticles = array();
            $resultSuppliersAffected = array();
            $resultPriceRangesAffected = array();
            $resultAffectedCategories = array();
            $resultCurrentCategory = array();
            // If search has results
            if ($results['count'] > 0) {
                $resultCount = $results['count'];
                $resultSuppliersAffected = $this->prepareSuppliers($suppliersFacets);
                $resultPriceRangesAffected = $this->preparePriceRanges($priceFacets);
                $resultAffectedCategories = $this->prepareCategories($categoryFacets);
                $resultCurrentCategory = $this->config['filter']['category'] ? $this->config['filter']['category'] : null;
            }

            // Generate page array
            $sPages = $this->generatePagesResultArray(
                $resultCount, $this->config['resultsPerPage'], $this->config['currentPage']
            );

            // Get additional information for each search result
            $articles = $this->helper->getLocalArticles($results);

            // Assign result to template
            $this->View()->assign(array(
                'sPages' => $sPages,
                'sLinks' => $links,
                'sPerPage' => $pageCounts,
                'sRequests' => $this->config,
                'sPriceFilter' => $this->configPriceFilter,
                'sCategoriesTree' => $this->getCategoryTree(
                    $resultCurrentCategory, $this->config['restrictSearchResultsToCategory']
                ),
                'sSearchResults' => array(
                    'sArticles' => $articles,
                    'sArticlesCount' => $resultCount,
                    'sSuppliers' => $resultSuppliersAffected,
                    'sPrices' => $resultPriceRangesAffected,
                    'sCategories' => $resultAffectedCategories,
                    'sLastCategory' => $resultCurrentCategory
                ),
            ));
        }

        return false;
    }

    /**
     * prepare category facet
     * @param $p13nCategories com\boxalino\p13n\api\thrift\FacetValue[]
     * @throws Enlight_Exception
     * @return array
     */
    private function prepareCategories($p13nCategories)
    {
        $categories = array();
        $mainCategoryId = Shopware()->Shop()->getCategory()->getId();
        $currentCategoryFilter = $this->config['filter']['category'] ? $this->config['filter']['category'] : null;
        $currentCategoryId = empty($currentCategoryFilter) ? $mainCategoryId : $currentCategoryFilter;
        try {
            $categoryCount = array();
            foreach($p13nCategories as $r) {
                $categoryCount[intval($r->stringValue)] = $r->hitCount;
            }
            foreach($this->categories as $r) {
                if (isset($categoryCount[$r['id']])) {
                    if ($r['path'] != null) {
                        $tree[$r['id']]['count'] += $categoryCount[$r['id']];
                    }
                }
            }
            foreach($this->categories as $r) {
                if ($r['parent'] == $currentCategoryId) {
                    $categories[] = array(
                        'id' => $r['id'],
                        'description' => $r['description'],
                        'count' => $r['count']
                    );
                }
            }
        } catch (PDOException $e) {
            throw new Enlight_Exception($e->getMessage());
        }
        return $categories;
    }

    /**
     * prepare category tree
     * @throws Enlight_Exception
     */
    private function prepareCategoriesTree()
    {
        $tree = array();
        $db = Shopware()->Db();
        $sql = $db->select()
                  ->from('s_categories', array('id', 'parent', 'description', 'path'))
                  ->where($db->quoteIdentifier('path') . 'IS NOT NULL');
        $mainCategoryId = Shopware()->Shop()->getCategory()->getId();
        try {
            $results = $db->fetchAll($sql);
            // build tree for current language
            foreach ($results as $r) {
                if (strpos($r['path'], '|'.$mainCategoryId.'|') !== false || $r['id'] == $mainCategoryId) {
                    $r['count'] = 0;
                    $tree[$r['id']] = $r;
                }
            }
        } catch (PDOException $e) {
            throw new Enlight_Exception($e->getMessage());
        }
        $this->categories = $tree;
    }

    private function prepareSearchConfiguration($term)
    {
        $config = array();
        $config['term'] = $config['sSearch'] = $term;
        $config['restrictSearchResultsToCategory'] = Shopware()->Shop()->get('parentID');
        $config['filter']['supplier'] = $config['sFilter']['supplier'] = (int) $this->Request()->sFilter_supplier;
        $config['filter']['category'] = $config['sFilter']['category'] = (int) $this->Request()->sFilter_category;
        $config['filter']['price'] = $config['sFilter']['price'] = (int) $this->Request()->sFilter_price;
        $config['filter']['propertyGroup'] = $this->Request()->sFilter_propertygroup;
        $config['filter']['propertygroup'] = $config['filter']['propertyGroup'];
        $config['sFilter']['propertygroup']= $config['filter']['propertyGroup'];

        $config['sortSearchResultsBy'] = $config['sSort'] = (int) $this->Request()->sSort;
        $config['sortSearchResultsByDirection'] = (int) $this->Request()->sOrder;

        if (!empty($this->Request()->sPage)) {
            $config['currentPage'] = (int) $this->Request()->sPage;
        } else {
            $config['currentPage'] = 1;
        }

        if (!empty($this->Request()->sPerPage)) {
            $config['resultsPerPage'] = (int) $this->Request()->sPerPage;
        } elseif (!empty(Shopware()->Config()->sFUZZYSEARCHRESULTSPERPAGE)) {
            $config['resultsPerPage'] = (int) Shopware()->Config()->sFUZZYSEARCHRESULTSPERPAGE;
        } else {
            $config['resultsPerPage'] = 8;
        }

        $config['sPerPage'] = $config['resultsPerPage'];

        $config['sSearchOrginal'] = $config['term'];
        $config['sSearchOrginal'] = htmlspecialchars($config['sSearchOrginal']);

        $config['shopLanguageId'] = Shopware()->Shop()->getId();
        $config['shopHasTranslations'] = Shopware()->Shop()->get('skipbackend') == true ? false : true;
        $config['shopCustomerGroup'] = Shopware()->System()->sUSERGROUP;
        $config['shopCustomerGroupDiscount'] = Shopware()->System()->sUSERGROUPDATA['discount'];
        $config['shopCustomerGroupMode'] = Shopware()->System()->sUSERGROUPDATA['mode'];
        $config['shopCustomerGroupTax'] = Shopware()->System()->sUSERGROUPDATA['tax'];
        $config['shopCustomerGroupId'] = Shopware()->System()->sUSERGROUPDATA['id'];
        $config['shopCurrencyFactor'] = Shopware()->System()->sCurrency['factor'];
        $this->config = $config;
    }

    /**
     * get current price range
     * @return NULL
     */
    private function getCurrentPriceRange() {
        if (!empty($this->config['filter']['price'])) {
            return $this->configPriceFilter[$this->config['filter']['price']];
        } else {
            return null;
        }
    }

    private function getSortOrder4()
    {
        $sortBy = $this->config['sortSearchResultsBy'];
        $direction = $this->config['sortSearchResultsByDirection'];
        // @todo: fix sorting by other fields
        switch ($sortBy) {
//            case 1:
//                $field = 'products_added'; // Datum
//                break;
//            case 2:
//                $field = 'products_sales'; //
//                break;
            case 3:
                $field = 'discountedPrice'; // Preis
                break;
            case 4:
                $field = 'discountedPrice'; // Preis
                $direction = 1;
                break;
            case 5:
                $l = Shopware()->Shop()->getLocale();
                $l = $l->getLocale();
                $position = strpos($l, '_');
                if ($position !== false)
                    $l = substr($l, 0, $position);
                $field = "title_$l"; // Bezeichnung
                break;
//            case 7:
//                $field = 'products_maxpurchase'; // Bewertung
//                break;
            default:
                return array();
        }

        return array(
            'field' => $field,
            'reverse' => $direction == 1
        );
    }
}
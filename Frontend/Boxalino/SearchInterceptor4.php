<?php

/**
 * search interceptor for shopware 4
 * uses smarty variables and request context
 */
class Shopware_Plugins_Frontend_Boxalino_SearchInterceptor4
    extends Shopware_Plugins_Frontend_Boxalino_SearchInterceptor
{
    private $categories;
    private $conf;

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
     * extract preview search result
     * @param array $response
     * @return array
     */
    public function getAjaxResult($response)
    {
        $results = $this->Helper()->extractResultsFromHitGroups(
            $response->prefixSearchResult->hitsGroups
        );
        $ids = array();
        foreach ($results as $result) {
            $ids[] = intval($result['products_group_id']);
        }

        $results = array();
        if (count($ids)) {
            $db = Shopware()->Db();
            $aId = $db->quoteIdentifier('a.id');
            $aName = $db->quoteIdentifier('a.name');
            $atName = $db->quoteIdentifier('at.name');
            $aDesc = $db->quoteIdentifier('a.description');
            $atDesc = $db->quoteIdentifier('at.description');
            $aDescLong = $db->quoteIdentifier('a.description_long');
            $atDescLong = $db->quoteIdentifier('at.description_long');
            $sql = $db->select()
                      ->from(
                            array('a' => 's_articles'),
                            array(
                                'articleID' => 'id',
                            )
                        )
                        ->joinInner(
                            array('ac' => 's_articles_categories_ro'),
                            $db->quoteIdentifier('ac.articleID') . " = $aId",
                            array()
                        )
                        ->joinInner(
                            array('c' => 's_categories'),
                            $db->quoteIdentifier('c.id') . ' = ' . $db->quoteIdentifier('ac.categoryID') .
                            ' AND ' . $db->quoteIdentifier('c.active') . ' = 1',
                            array()
                        )
                        ->joinLeft(
                            array('ai' => 's_articles_img'),
                            $db->quoteIdentifier('ai.articleID') . " = $aId AND " .
                            $db->quoteIdentifier('ai.main') . ' = 1 AND ' .
                            $db->quoteIdentifier('ai.article_detail_id') . ' IS NULL',
                            array(
                                'image' => 'img',
                                'mediaId' => 'media_id',
                            )
                        )
                        ->joinLeft(
                            array('at' => 's_articles_translations'),
                            $db->quoteIdentifier('at.articleID') . " = $aId",
                            array(
                                'articleName' => new Zend_Db_Expr(
                                    "IF($atName IS NULL OR $atName = '', $aName, $atName)"
                                ),
                                'description' => new Zend_Db_Expr(
                                    "IF($atDescLong IS NULL OR $atDescLong = '', IF(TRIM($aDesc) != '', $aDesc, $aDescLong), IF(TRIM($atDesc) = '', $atDescLong, $atDesc))"
                                ),
                            )
                        )
                        ->where($db->quoteIdentifier('a.active') . ' = ?', 1)
                        ->where("$aId IN (?)", $ids);

            $unordered_results = $db->fetchAll($sql);
            $basePath = $this->Request()->getScheme() . '://' . $this->Request()->getHttpHost() . $this->Request()->getBasePath();
            if (!empty($unordered_results)) {
                $models = Shopware()->Models();
                $router = Shopware()->Front()->Router();
                foreach ($ids as $id) {
                    foreach ($unordered_results as $key => &$result) {
                        if ($result['articleID'] == $id) {
                            if (empty($result['type'])) $result['type'] = 'article';
                            if (!empty($result['mediaId'])) {
                                /** @var $mediaModel \Shopware\Models\Media\Media */
                                $mediaModel = $models->find('Shopware\Models\Media\Media', $result['mediaId']);
                                if ($mediaModel != null) {
                                    $result['thumbNails'] = array_values($mediaModel->getThumbnails());
                                    $result['image'] = $result['thumbNails'][1];
                                }
                            }
                            $result['link'] = $router->assemble(array('controller' => 'detail', 'sArticle' => $result['articleID'], 'title' => $result['name']));

                            $results[] = $result;
                            unset($unordered_results[$key]);
                            break;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * perform search
     * @param Enlight_Event_EventArgs $arguments
     * @return boolean
     */
    public function search(Enlight_Event_EventArgs $arguments)
    {
        if (!$this->Config()->get('boxalino_search_enabled')) {
            return null;
        }
        $this->init($arguments);

        $term = $this->getSearchTerm();

        // Check if we have a one to one match for ordernumber, then redirect
        $location = $this->searchFuzzyCheck($term);
        if (!empty($location)) {
            return $this->Controller()->redirect($location);
        }

        $this->View()->loadTemplate('frontend/search/fuzzy.tpl');

        // Check if search term met minimum length
        if (strlen($term) >= (int) $this->Config()->sMINSEARCHLENGHT) {

            // Load search configuration
            $this->prepareSearchConfiguration($term);

            $config = $this->get('config');
            $pageCounts = array_values(explode('|', $config->get('fuzzySearchSelectPerPage')));
            $pageCount = ($this->conf['currentPage']) * $this->conf['resultsPerPage'];
            $pageOffset = ($this->conf['currentPage'] - 1) * $this->conf['resultsPerPage'];

            $this->prepareCategoriesTree();
            $currentCategoryFilter = $this->conf['filter']['category'] ? $this->conf['filter']['category'] : null;
            $choice = $this->Helper()->search(
                $term, $pageOffset, $pageCount, array(
                    'category' => $currentCategoryFilter,
                    'categoryName' => array($this->categories[$currentCategoryFilter]['description']),
                    'price' => $this->getCurrentPriceRange(),
                    'supplier' => !empty($this->conf['filter']['supplier']) ? $this->conf['filter']['supplier'] : null,
                    'property_values' => $this->getCurrentProperties(),
                    'sort' => $this->getSortOrder4()
                )
            );
            $results = $this->Helper()->extractResults($choice);
            $suppliersFacets = $this->Helper()->extractFacet($choice, 'products_supplier');
            $priceFacets = $this->Helper()->extractFacet($choice, 'discountedPrice');
            $categoryFacets = $this->Helper()->extractFacet($choice, 'categories');
            $propertyFacets = $this->Helper()->extractFacet($choice, 'products_property_values');
            $ids = $this->Helper()->extractFacet($choice, 'id');

            // Prepare property facets
            $propertyFacetValues = array();
            foreach ($propertyFacets as $facetValue) {
                $valueID = (int) $facetValue->stringValue;
                $propertyFacetValues[$valueID] = array(
                    'count' => $facetValue->hitCount,
                    'selected' => $facetValue->selected,
                );
            }

            // Prepare property values
            $db = Shopware()->Db();
            $sql = $db->select()
                      ->from(
                        's_filter_values',
                        array(
                            'valueID' => 'id',
                            'optionID' => 'optionID',
                            'name' => 'value',
                        )
                      )
                      ->order(array(
                          'optionID',
                          'position',
                      ));
            $propertyValues = array();
            foreach ($db->fetchAll($sql) as $values) {
                if (!array_key_exists($values['optionID'], $propertyValues))
                    $propertyValues[$values['optionID']] = array();
                $selected = false;
                $count = 0;
                $valueID = (int) $values['valueID'];
                if (array_key_exists($valueID, $propertyFacetValues)) {
                    $selected = $propertyFacetValues[$valueID]['selected'];
                    $count = $propertyFacetValues[$valueID]['count'];
                }
                if ($count > 0) {
                    $propertyValues[(int) $values['optionID']][$valueID] = array(
                        'name' => $values['name'],
                        'selected' => $selected,
                        'count' => $count,
                    );
                }
            }

            // Prepare property options
            $sql = $db->select()
                      ->from(
                        's_filter_options',
                        array(
                            'optionID' => 'id',
                            'name' => 'name',
                        )
                      )
                      ->where('filterable = 1');
            $propertyOptions = array();
            foreach ($db->fetchAll($sql) as $option) {
                $selected = false;
                $count = 0;
                $optionID = (int) $option['optionID'];
                if (array_key_exists($optionID, $propertyValues)) {
                    foreach ($propertyValues[$optionID] as $valueID => $valueProperties) {
                        if (array_key_exists($valueID, $propertyFacetValues)) {
                            $count += $propertyFacetValues[$valueID]['count'];
                            if ($propertyFacetValues[$valueID]['selected']) {
                                $selected = true;
                            }
                        }
                    }
                }
                if ($count > 0) {
                    $propertyOptions[$optionID] = array(
                        'name' => $option['name'],
                        'selected' => $selected,
                        'removeAll' => 1, // for shopware 4 enterprise
                        'count' => $count,
                    );
                }
            }

            // Prepare property groups
            $sql = $db->select()
                      ->from(
                          's_filter',
                          array(
                              'filerID' => 'id', // sic!
                              'name' => 'name',
                          )
                      )
                      ->join(
                          's_filter_relations',
                          $db->quoteIdentifier('s_filter_relations.groupID') .
                          ' = ' .
                          $db->quoteIdentifier('s_filter.id'),
                          array(
                              'optionIDs' => new Zend_Db_Expr(
                                  'GROUP_CONCAT(DISTINCT ' .
                                  $db->quoteIdentifier('s_filter_relations.optionID') .
                                  ')'
                              )
                          )
                      )
                      ->group('s_filter.id')
                      ->order('s_filter.position');
            $propertyGroups = $db->fetchAll($sql);
            foreach ($propertyGroups as $key => $group) {
                $count = 0;
                $optionIDs = explode(',', $group['optionIDs']);
                foreach ($optionIDs as $optionID) {
                    $optionID = (int) $optionID;
                    if (array_key_exists($optionID, $propertyOptions)) {
                        $count += $propertyOptions[$optionID]['count'];
                    }
                }
                $propertyGroups[$key]['count'] = $count;
                if ($count == 0) {
                    unset($propertyGroups[$key]);
                }
            }

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
                $resultCurrentCategory = $this->conf['filter']['category'] ? $this->conf['filter']['category'] : null;
            }

            // Prepare links for template
            $links = $this->searchDefaultPrepareLinks($this->conf);

            // Generate page array
            $sPages = $this->generatePagesResultArray(
                $resultCount, $this->conf['resultsPerPage'], $this->conf['currentPage']
            );

            // Get additional information for each search result
            $articles = $this->Helper()->getLocalArticles($results);

            // Assign result to template
            $this->View()->assign(array(
                'sPages' => $sPages,
                'sLinks' => $links,
                'sPerPage' => $pageCounts,
                'sRequests' => $this->conf,
                'sPriceFilter' => $this->configPriceFilter,
                'sCategoriesTree' => $this->getCategoryTree(
                    $resultCurrentCategory, $this->conf['restrictSearchResultsToCategory']
                ),
                'sSearchResults' => array(
                    'sArticles' => $articles,
                    'sArticlesCount' => $resultCount,
                    'sSuppliers' => $resultSuppliersAffected,
                    'sPrices' => $resultPriceRangesAffected,
                    'sCategories' => $resultAffectedCategories,
                    'sLastCategory' => $resultCurrentCategory,
                    'sPropertyGroups' => $propertyGroups,
                    'sPropertyOptions' => $propertyOptions,
                    'sPropertyValues' => $propertyValues,
                ),
            ));
        }

        return false;
    }

    /**
     * Prepare fuzzy search links
     *
     * @param array $config
     * @return array
     */
    protected function searchDefaultPrepareLinks(array $config)
    {
        $links = array();

        $links['sLink'] = Shopware()->Config()->BaseFile . '?sViewport=search';
        $links['sLink'] .= '&sSearch=' . urlencode($config['term']);
        $links['sSearch'] = Shopware()->Front()->Router()->assemble(array('sViewport' => 'search'));

        $links['sPage'] = $links['sLink'];
        $links['sPerPage'] = $links['sLink'];
        $links['sSort'] = $links['sLink'];

        $links['sFilter']['category'] = $links['sLink'];
        $links['sFilter']['supplier'] = $links['sLink'];
        $links['sFilter']['price'] = $links['sLink'];
        $links['sFilter']['propertygroup'] = $links['sLink'];

        $filterTypes = array('supplier', 'category', 'price', 'propertygroup');

        foreach ($filterTypes as $filterType) {
            if (empty($config['filter'][$filterType])) {
                continue;
            }
            $links['sPage'] .= "&sFilter_$filterType=" . $config['filter'][$filterType];
            $links['sPerPage'] .= "&sFilter_$filterType=" . $config['filter'][$filterType];
            $links['sSort'] .= "&sFilter_$filterType=" . $config['filter'][$filterType];

            foreach ($filterTypes as $filterType2) {
                if ($filterType != $filterType2) {
                    $links['sFilter'][$filterType2] .= "&sFilter_$filterType=" . urlencode($config['filter'][$filterType]);
                }
            }
        }

        foreach (array('sortSearchResultsBy' => 'sSort', 'resultsPerPage' => 'sPerPage') as $property => $name) {
            if (!empty($config[$property])) {
                if ($name != 'sPage') {
                    $links['sPage'] .= "&$name=" . $config[$property];
                }
                if ($name != 'sPerPage') {
                    $links['sPerPage'] .= "&$name=" . $config[$property];
                }
                $links['sFilter']['__'] .= "&$name=" . $config[$property];
            }
        }

        foreach ($filterTypes as $filterType) {
            $links['sFilter'][$filterType] .= $links['sFilter']['__'];
        }

        $links['sSupplier'] = $links['sSort'];

        return $links;
    }

    /**
     * Generate array with pages for template
     * @param $resultCount int Count of search results
     * @param $resultsPerPage int How many products per page
     * @param $currentPage int Current page offset
     * @return array
     */
    public function generatePagesResultArray($resultCount, $resultsPerPage, $currentPage)
    {
        $numberPages = ceil($resultCount / $resultsPerPage);
        if ($numberPages > 1) {
            for ($i = 1; $i <= $numberPages; $i++) {
                $sPages['pages'][$i] = $i;
            }
            // Previous page
            if ($currentPage != 1) {
                $sPages["before"] = $currentPage - 1;
            } else {
                $sPages["before"] = null;
            }
            // Next page
            if ($currentPage != $numberPages) {
                $sPages["next"] = $currentPage +1;
            } else {
                $sPages["next"] = null;
            }
        }
        return $sPages;
    }

    /**
     * @param $p13nSuppliers com\boxalino\p13n\api\thrift\FacetValue[]
     * @return array
     */
    private function prepareSuppliers($p13nSuppliers) {
        $keys = array();
        $hitCount = array();
        foreach($p13nSuppliers as $s) {
            $id = intval($s->stringValue);
            $keys[] = $id;
            $hitCount[$id] = $s->hitCount;
        }

        $sql = '
            SELECT s.*
            FROM s_articles_supplier s
            WHERE s.id IN (' . join(',', $keys) . ')
            ORDER BY s.name
        ';

        $suppliers = array();
        try {
            $result = Shopware()->Db()->fetchAll($sql);
            if (is_array($result)) {
                foreach ($result as $row) {
                    $row["count"] = $hitCount[$row["id"]];
                    $suppliers[$row["id"]] = $row;
                }
            }
        } catch (PDOException $e) {
            Shopware()->PluginLogger()->debug('Boxalino SearchInterceptor 4: error preparing suppliers, PDOException: '. $e->getMessage());
        }
        return $suppliers;
    }

    /**
     * @param $p13nPrices com\boxalino\p13n\api\thrift\FacetValue[]
     * @return array
     */
    private function preparePriceRanges($p13nPrices) {
        // p13n does return only one value for ranges
        $priceRange = current($p13nPrices);
        $count = $priceRange->hitCount;
        $from = floatval($priceRange->rangeFromInclusive);
        $to = floatval($priceRange->rangeToExclusive);

        $searchResultPriceFilters = array();
        $configSearchPriceFilter = $this->config['filter']['price'];
        $filterPrice = empty($configSearchPriceFilter) ? $this->configPriceFilter : $configSearchPriceFilter;

        foreach ($filterPrice as $key => $filter) {
            // @note: we can't get an individual count per "subrange"
            if (
                $filter['start'] <= $from && $filter['end'] > $from ||
                $filter['start'] <= $to && $filter['end'] > $to
            ) {
                $searchResultPriceFilters[$key] = $count;
            }
        }
        return $searchResultPriceFilters;
    }

    /**
     * prepare category facet
     * @param $p13nCategories com\boxalino\p13n\api\thrift\FacetValue[]
     * @return array
     */
    private function prepareCategories($p13nCategories)
    {
        $categories = array();
        $mainCategoryId = Shopware()->Shop()->getCategory()->getId();
        $currentCategoryFilter = $this->conf['filter']['category'] ? $this->conf['filter']['category'] : null;
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
            Shopware()->PluginLogger()->debug('Boxalino SearchInterceptor 4: error preparing categories, PDOException: '. $e->getMessage());
        }
        return $categories;
    }

    /**
     * prepare category tree
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
            Shopware()->PluginLogger()->debug('Boxalino SearchInterceptor 4: error preparing category tree, PDOException: '. $e->getMessage());
        }
        $this->categories = $tree;
    }

    /**
     * Returns a category tree
     *
     * @param int $id
     * @param int $mainId
     * @return array
     */
    protected function getCategoryTree($id, $mainId)
    {
        $sql = '
            SELECT
                `id` ,
                `description`,
                `parent`
            FROM `s_categories`
            WHERE `id`=?
        ';
        $cat = Shopware()->Db()->fetchRow($sql, array($id));
        if (empty($cat['id']) || $id == $cat['parent'] || $id == $mainId) {
            return array();
        } else {
            $cats = $this->getCategoryTree($cat['parent'], $mainId);
            $cats[$id] = $cat;
            return $cats;
        }
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
        } elseif (!empty($this->Config()->sFUZZYSEARCHRESULTSPERPAGE)) {
            $config['resultsPerPage'] = (int) $this->Config()->sFUZZYSEARCHRESULTSPERPAGE;
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
        $this->conf = $config;
    }

    /**
     * get current price range
     * @return array|NULL
     */
    private function getCurrentPriceRange() {
        if (!empty($this->conf['filter']['price'])) {
            return $this->configPriceFilter[$this->conf['filter']['price']];
        } else {
            return null;
        }
    }

    /**
     * get current properties
     * @return array|NULL
     */
    private function getCurrentProperties() {
        if (!empty($this->conf['filter']['propertygroup'])) {
            $values = explode('_', $this->conf['filter']['propertygroup']);
            if(($key = array_search('1', $values)) !== false) {
                unset($values[$key]);
            }
            return $values;
        } else {
            return null;
        }
    }

    private function getSortOrder4()
    {
        $sortBy = $this->conf['sortSearchResultsBy'];
        $direction = $this->conf['sortSearchResultsByDirection'];
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
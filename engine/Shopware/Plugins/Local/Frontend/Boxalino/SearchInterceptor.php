<?php
/**
 * search interceptor for shopware 5 and following
 * uses SearchBundle
 */
class Shopware_Plugins_Frontend_Boxalino_SearchInterceptor
{
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_Bootstrap
     */
    protected $bootstrap;

    /**
     * @var Shopware\Components\DependencyInjection\Container
     */
    protected $container;

    /**
     * @var Shopware_Controllers_Frontend_Index
     */
    protected $controller;

    /**
     * @var Enlight_Event_EventManager
     */
    protected $eventManager;

    /**
     * @var FacetHandlerInterface[]
     */
    protected $facetHandlers;

    /**
     * @var Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    protected $helper;

    /**
     * @var Enlight_Controller_Request_Request
     */
    protected $request;

    /**
     * @var Enlight_View_Default
     */
    protected $view;

    /**
     * constructor
     * @param Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap
     */
    public function __construct(Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->container = Shopware()->Container();
        $this->helper = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance();
        $this->eventManager = Enlight()->Events();
    }

    /**
     * perform autocompletion suggestion
     * @param Enlight_Event_EventArgs $arguments
     * @return boolean
     */
    public function ajaxSearch(Enlight_Event_EventArgs $arguments)
    {
        if (!Shopware()->Config()->get('boxalino_search_enabled')) {
            return false;
        }
        $this->init($arguments);

        /* @var Zend_Controller_Response_Http $response */
        $response = $this->controller->Response();

        Enlight()->Plugins()->Controller()->Json()->setPadding();

        $this->View()->loadTemplate('frontend/search/ajax.tpl');

        $term = $this->getSearchTerm();
        if (empty($term) || strlen($term) < Shopware()->Config()->MinSearchLenght) {
            return false;
        }

        $results = $this->helper->extractResults(
            $this->helper->quickSearch($term)
        );
        $sResults = $this->prepareResults($results);

        $this->View()->sSearchRequest = array('sSearch' => $term);
        $this->View()->sSearchResults = array(
            'sResults' => $sResults,
            'sArticlesCount' => $results['count'],
        );

        return false;
    }

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

            /* @var ProductContextInterface $context */
            $context  = $this->get('shopware_storefront.context_service')->getProductContext();
            /* @var Shopware\Bundle\SearchBundle\Criteria $criteria */
            $criteria = $this->get('shopware_search.store_front_criteria_factory')
                             ->createSearchCriteria($this->Request(), $context);
            $facets = $this->createFacets($criteria, $context);

            $options = $this->getOptionsFromFacets($facets);
            $options['sort'] = $this->getSortOrder($criteria);

            $config = $this->get('config');
            $pageCounts = array_values(explode('|', $config->get('fuzzySearchSelectPerPage')));
            $pageCount = $criteria->getLimit();
            $pageOffset = $criteria->getOffset();

            $choice = $this->helper->search(
                $term, $pageOffset, $pageCount, $options
            );
            $results = $this->helper->extractResults($choice);
            $facets = $this->updateFacetsWithResult($facets, $choice);

            // Get additional information for each search result
            $articles = $this->helper->getLocalArticles($results);

            $request = $this->Request();
            $params = $request->getParams();
            $params['sSearchOrginal'] = $term;

            // Assign result to template
            $this->View()->assign(array(
                'term' => $term,
                'criteria' => $criteria,
                'facets' => $facets,
                'sPage' => $request->getParam('sPage', 1),
                'sSort' => $request->getParam('sSort', 7),
                'sTemplate' => $params['sTemplate'],
                'sPerPage' => $pageCounts,
                'sRequests' => $params,
                'shortParameters' => $this->get('query_alias_mapper')->getQueryAliases(),
                'pageSizes' => $pageCounts,
                'ajaxCountUrlParams' => ['sCategory' => $context->getShop()->getCategory()->getId()],
                'sSearchResults' => array(
                    'sArticles' => $articles,
                    'sArticlesCount' => ($results['count'] > 0 ? $results['count'] : 0),
                ),
                'productBoxLayout' => $config->get('searchProductBoxLayout'),
            ));
        }

        return false;
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
     * Get service from resource loader
     *
     * @param string $name
     * @return mixed
     */
    public function get($name)
    {
        return $this->container->get($name);
    }

    /**
     * Returns view instance
     *
     * @return Enlight_View_Default
     */
    public function View()
    {
        return $this->view;
    }

    /**
     * Returns request instance
     *
     * @return Enlight_Controller_Request_Request
     */
    public function Request()
    {
        return $this->request;
    }


    /**
     * @return string
     */
    protected function getSearchTerm()
    {
        $term = $this->Request()->get('sSearch', '');

        $term = trim(strip_tags(htmlspecialchars_decode(stripslashes($term))));

        // we have to strip the / otherwise broken urls would be created e.g. wrong pager urls
        $term = str_replace('/', '', $term);

        return $term;
    }

    /**
     * Search product by order number
     *
     * @param string $search
     * @return string
     */
    protected function searchFuzzyCheck($search)
    {
        $minSearch = empty(Shopware()->Config()->sMINSEARCHLENGHT) ? 2 : (int) Shopware()->Config()->sMINSEARCHLENGHT;
        if (!empty($search) && strlen($search) >= $minSearch) {
            $sql = '
                SELECT DISTINCT articleID
                FROM s_articles_details
                WHERE ordernumber = ?
                GROUP BY articleID
                LIMIT 2
            ';
            $articles = Shopware()->Db()->fetchCol($sql, array($search));

            if (empty($articles)) {
                $sql = "
                    SELECT DISTINCT articleID
                    FROM s_articles_details
                    WHERE ordernumber = ?
                    OR ? LIKE CONCAT(ordernumber, '%')
                    GROUP BY articleID
                    LIMIT 2
                ";
                $articles = Shopware()->Db()->fetchCol($sql, array($search, $search));
            }
        }
        if (!empty($articles) && count($articles) == 1) {
            $sql = '
                SELECT ac.articleID
                FROM  s_articles_categories_ro ac
                INNER JOIN s_categories c
                    ON  c.id = ac.categoryID
                    AND c.active = 1
                    AND c.id = ?
                WHERE ac.articleID = ?
                LIMIT 1
            ';
            $articles = Shopware()->Db()->fetchCol($sql, array(Shopware()->Shop()->get('parentID'), $articles[0]));
        }
        if (!empty($articles) && count($articles) == 1) {
            return $this->controller->Front()->Router()->assemble(array('sViewport' => 'detail', 'sArticle' => $articles[0]));
        }
    }

    /**
     * @return Shopware\Bundle\SearchBundle\FacetHandlerInterface[]
     */
    protected function registerFacetHandlers()
    {
        // did not find a way to use the service tag "facet_handler_dba"
        // it seems the dependency injection CompilerPass is not available to plugins?
        $facetHandlerIds = [
            'vote_average',
            'shipping_free',
            'product_attribute',
            'immediate_delivery',
            'manufacturer',
            'property',
            'category',
            'price',
        ];
        $facetHandlers = [];
        foreach ($facetHandlerIds as $id) {
            $facetHandlers[] = $this->container->get("shopware_searchdbal.${id}_facet_handler_dbal");
        }

        return $facetHandlers;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetInterface $facet
     * @throws \Exception
     * @return Shopware\Bundle\SearchBundle\FacetHandlerInterface
     */
    protected function getFacetHandler(SearchBundle\FacetInterface $facet)
    {
        if ($this->facetHandlers == null) {
            $this->facetHandlers = $this->registerFacetHandlers();
        }
        foreach ($this->facetHandlers as $handler) {
            if ($handler->supportsFacet($facet)) {
                return $handler;
            }
        }

        throw new \Exception(sprintf('Facet %s not supported', get_class($facet)));
    }

    /**
     * @param Shopware\Bundle\SearchBundle\Criteria $criteria
     * @param ShopContextInterface $context
     * @throws \Exception
     * @return Shopware\Bundle\SearchBundle\FacetResultInterface[]
     */
    protected function createFacets(Shopware\Bundle\SearchBundle\Criteria $criteria, ShopContextInterface $context)
    {
        $facets = array();

        foreach ($criteria->getFacets() as $facet) {
            $handler = $this->getFacetHandler($facet);

            $result = $handler->generateFacet($facet, $criteria, $context);

            if (!$result) {
                continue;
            }

            if (!is_array($result)) {
                $result = [$result];
            }

            $facets = array_merge($facets, $result);
        }

        return $facets;
    }

    protected function prepareResults($p13nResults)
    {
        $sResults = array();
        foreach($p13nResults['results'] as $p13nResult) {
            $result = array(
                'key' => $p13nResult['id'],
                'articleID' => intval($p13nResult['products_group_id']),
                'relevance' => '1000',
                'price' => $p13nResult['standardPrice'],
                'supplierID' => $p13nResult['products_supplier'],
                'datum' => '2014-04-01',
                'sales' => '0',
                'name' => $p13nResult['title'],
                'description' => $p13nResult['body'],
                'image' => null,
                'mediaId' => '' . intval($p13nResult['products_mediaId']),
                'extension' => null,
                'vote' => '0.00|0'
            );
            $result['link'] = $this->controller->Front()->Router()->assemble(array('controller' => 'detail', 'sArticle' => $p13nResult['products_group_id'], 'title' => $result['name']));

            $mediaModel = Shopware()->Models()->find('Shopware\Models\Media\Media', intval($p13nResult['products_mediaId']));
            if ($mediaModel != null) {
                $result['thumbNails'] = array_values($mediaModel->getThumbnails());
                // @deprecated just for the downward compatibility use the thumbNail Array instead
                $result['image'] = $result['thumbNails'][1];
            }

            $sResults[] = $result;
        }
        return $sResults;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetResultInterface[] $facets
     * @return array
     */
    protected function getOptionsFromFacets($facets)
    {
        $options = [];
        foreach ($facets as $facet) {
            if ($facet instanceof Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup) {
                /* @var Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup $facet */
                $options = array_merge($options, $this->getOptionsFromFacets($facet->getFacetResults()));
                break;
            }
            $key = 'property_values';
            switch ($facet->getFacetName()) {
                case 'price':
                    $min = $max = null;
                    if ($facet->isActive()) {
                        $min = $facet->getActiveMin();
                        $max = $facet->getActiveMax();
                    }
                    $options['price'] = [
                        'start' => $min,
                        'end'   => $max,
                    ];
                    break;
                case 'category':
                    $id = $label = null;
                    if ($facet->isActive()) {
                        $value = $this->getLowestActiveTreeItem($facet->getValues());
                        if ($value instanceof Shopware\Bundle\SearchBundle\FacetResult\TreeItem) {
                            $id = $value->getId();
                            $label = $value->getLabel();
                        }
                    }
                    $options['category'] = $id;
                    $options['categoryName'] = $label;
                    break;
                case 'manufacturer':
                    $key = 'brand';
                case 'property':
                    if (!array_key_exists($key, $options)) {
                        $options[$key] = [];
                    }
                    foreach ($facet->getValues() as $value) {
                        /* @var Shopware\Bundle\SearchBundle\FacetResult\ValueListItem|Shopware\Bundle\SearchBundle\FacetResult\MediaListItem $value */
                        if ($value->isActive()) {
                            $options[$key][] = ($key == 'brand' ? $value->getLabel() : (string) $value->getId());
                        }
                    }
                    break;
            }
        }
        return $options;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetResult\TreeItem[] $values
     * @return null|Shopware\Bundle\SearchBundle\FacetResult\TreeItem
     */
    protected function getLowestActiveTreeItem($values)
    {
        foreach ($values as $value) {
            if ($value->isActive()) {
                $innerValues = $value->getValues();
                if (count($innerValues)) {
                    $innerValue = $this->getLowestActiveTreeItem($innerValues);
                    if ($innerValue instanceof Shopware\Bundle\SearchBundle\FacetResult\TreeItem) {
                        return $innerValue;
                    }
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetResultInterface[] $facets
     * @param \com\boxalino\p13n\api\thrift\ChoiceResponse $choiceResponse
     * @return Shopware\Bundle\SearchBundle\FacetResultInterface[]
     */
    protected function updateFacetsWithResult($facets, $choiceResponse) {
        foreach ($facets as $key => $facet) {
            if ($facet instanceof Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup) {
                /* @var Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup $facet */
                $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup(
                    $this->updateFacetsWithResult($facet->getFacetResults(), $choiceResponse),
                    $facet->getLabel(),
                    $facet->getFacetName(),
                    $facet->getAttributes(),
                    $facet->getTemplate()
                );
                break;
            }
            $productPropertyName = 'property_values';
            switch ($facet->getFacetName()) {
                case 'price':
                    /* @var Shopware\Bundle\SearchBundle\FacetResult\RangeFacetResult $facet
                     * @var com\boxalino\p13n\api\thrift\FacetValue $FacetValue */
                    $FacetValue = current($this->helper->extractFacet($choiceResponse, 'discountedPrice'));
                    $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\RangeFacetResult(
                        $facet->getFacetName(),
                        $facet->isActive(),
                        $facet->getLabel(),
                        $facet->getMin(),
                        $facet->getMax(),
                        (float) $FacetValue->rangeFromInclusive,
                        (float) $FacetValue->rangeToInclusive,
                        $facet->getMinFieldName(),
                        $facet->getMaxFieldName(),
                        $facet->getAttributes(),
                        $facet->getTemplate()
                    );
                    break;
                case 'category':
                    /* @var Shopware\Bundle\SearchBundle\FacetResult\TreeFacetResult $facet */
                    $unorderedFacetValues = $this->helper->extractFacet($choiceResponse, 'categories');
                    $FacetValues = [];
                    foreach ($unorderedFacetValues as $FacetValue) {
                        $FacetValues[$FacetValue->hierarchyId] = $FacetValue;
                    }

                    $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\TreeFacetResult(
                        $facet->getFacetName(),
                        $facet->getFieldName(),
                        $facet->isActive(),
                        $facet->getLabel(),
                        $this->updateTreeItemsWithFacetValue($facet->getValues(), $FacetValues),
                        $facet->getAttributes(),
                        $facet->getTemplate()
                    );
                    break;
                case 'manufacturer':
                    $productPropertyName = 'brand';
                case 'property':
                    /* @var Shopware\Bundle\SearchBundle\FacetResult\MediaListFacetResult|Shopware\Bundle\SearchBundle\FacetResult\ValueListFacetResult $facet */
                    $FacetValues = $this->helper->extractFacet($choiceResponse, 'products_' . $productPropertyName);
                    $valueList = [];
                    /* @var com\boxalino\p13n\api\thrift\FacetValue $FacetValue */
                    foreach ($FacetValues as $FacetValue) {
                        foreach ($facet->getValues() as $valueKey => $originalValue) {
                            if ($productPropertyName == 'brand') {
                                $check = (trim($originalValue->getLabel()) == trim($FacetValue->stringValue));
                            } else {
                                $check = ($originalValue->getId() == $FacetValue->stringValue);
                            }
                            $hitCount = 0;
                            $active = $originalValue->isActive();
                            if ($check) {
                                $hitCount = $FacetValue->hitCount;
                                $active = $FacetValue->selected;
                            }
                            $args = [];
                            $args[] = $originalValue->getId();
                            $args[] = $originalValue->getLabel() . ' (' . $hitCount . ')';
                            $args[] = $active;
                            if ($originalValue instanceof Shopware\Bundle\SearchBundle\FacetResult\MediaListItem) {
                                $args[] = $originalValue->getMedia();
                            }
                            $args[] = $originalValue->getAttributes();

                            if (!array_key_exists($valueKey, $valueList) || $check) {
                                $r = new ReflectionClass(get_class($originalValue));
                                $valueList[$valueKey] = $r->newInstanceArgs($args);
                            }
                        }
                    }

                    $facetResultClass = get_class($facet);
                    $facets[$key] = new $facetResultClass(
                        $facet->getFacetName(),
                        $facet->isActive(),
                        $facet->getLabel(),
                        $valueList,
                        $facet->getFieldName(),
                        $facet->getAttributes(),
                        $facet->getTemplate()
                    );
                    break;
            }
        }
        return $facets;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetResult\TreeItem[] $values
     * @param com\boxalino\p13n\api\thrift\FacetValue[] $FacetValues
     * @return Shopware\Bundle\SearchBundle\FacetResult\TreeItem[]
     */
    protected function updateTreeItemsWithFacetValue($values, $FacetValues) {
        /* @var Shopware\Bundle\SearchBundle\FacetResult\TreeItem $value */
        foreach ($values as $key => $value) {
            $id = (string) $value->getId();
            $label = $value->getLabel();
            $innerValues = $value->getValues();

            if (count($innerValues)) {
                $innerValues = $this->updateTreeItemsWithFacetValue($innerValues, $FacetValues);
            }

            if (array_key_exists($id, $FacetValues)) {
                $label .= ' (' . $FacetValues[$id]->hitCount . ')';
            }

            $values[$key] = new Shopware\Bundle\SearchBundle\FacetResult\TreeItem(
                $value->getId(),
                $label,
                $value->isActive(),
                $innerValues,
                $value->getAttributes()
            );
        }
        return $values;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\Criteria $criteria
     * @return array
     */
    public function getSortOrder(Shopware\Bundle\SearchBundle\Criteria $criteria)
    {
        /* @var Shopware\Bundle\SearchBundle\Sorting\Sorting $sort */
        $sort = current($criteria->getSortings());
        switch ($sort->getName()) {
            case 'popularity':
                $field = 'products_sales';
                break;
            case 'prices':
                $field = 'discountedPrice';
                break;
            case 'product_name':
                $field = 'title';
                break;
            case 'release_date':
                $field = 'products_releasedate';
                break;
            default:
                return array();
        }

        return array(
            'field' => $field,
            'reverse' => ($sort->getDirection() == Shopware\Bundle\SearchBundle\SortingInterface::SORT_DESC)
        );
    }
}
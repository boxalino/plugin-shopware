<?php

use Doctrine\DBAL\Connection;
/**
 * search interceptor for shopware 5 and following
 * uses SearchBundle
 */
class Shopware_Plugins_Frontend_Boxalino_SearchInterceptor
    extends Shopware_Plugins_Frontend_Boxalino_Interceptor {
    
    /**
     * @var Shopware\Components\DependencyInjection\Container
     */
    private $container;

    /**
     * @var Enlight_Event_EventManager
     */
    protected $eventManager;

    /**
     * @var FacetHandlerInterface[]
     */
    protected $facetHandlers;

    /**
     * constructor
     * @param Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap
     */
    public function __construct(Shopware_Plugins_Frontend_Boxalino_Bootstrap $bootstrap) {
        parent::__construct($bootstrap);
        $this->container = Shopware()->Container();
        $this->eventManager = Enlight()->Events();
    }

    /**
     * perform autocompletion suggestion
     * @param Enlight_Event_EventArgs $arguments
     * @return boolean
     */
    public function ajaxSearch(Enlight_Event_EventArgs $arguments) {
        if (!$this->Config()->get('boxalino_search_enabled')) {
            return null;
        }
        $this->init($arguments);

        Enlight()->Plugins()->Controller()->Json()->setPadding();

        $term = $this->getSearchTerm();
        if (empty($term)) {
            return false;
        }
        
        $requests = array();
        $offset = 0;
        $hitCount = $this->Helper()->getSearchLimit();
        $requests[] = $this->Helper()->newAutocompleteRequest($term, $hitCount);
        $requests = array_merge($requests, $this->createAutocompleteRequests($term, $offset, $hitCount));
        $responses = $this->Helper()->autocompleteAll($requests);
        $suggestions = $this->Helper()->getAutocompleteSuggestions($responses, $hitCount);
        $response = array_shift($responses);

        $sResults = $this->getAjaxResult($response);
        $router = Shopware()->Front()->Router();
        foreach ($sResults as $key => $result) {
            $sResults[$key]['name'] = $result['articleName'];
            $sResults[$key]['link'] = $router->assemble(array(
                'controller' => 'detail',
                'sArticle' => $result['articleID'],
                'title' => $result['articleName']
            ));
        }

        if (version_compare(Shopware::VERSION, '5.0.0', '>=')) {
            $this->View()->loadTemplate('frontend/search/ajax.tpl');
            $this->View()->addTemplateDir($this->Bootstrap()->Path() . 'Views/');
            $this->View()->extendsTemplate('frontend/ajax.tpl');
        } else {
            $this->View()->addTemplateDir($this->Bootstrap()->Path() . 'Views/');
            $this->View()->loadTemplate('frontend/ajax4.tpl');
        }
        $totalHitCount = count($sResults);
        if (isset($response->prefixSearchResult) && isset($response->prefixSearchResult->totalHitCount)) {
          $totalHitCount = $response->prefixSearchResult->totalHitCount;
        }
        if ($totalHitCount == 0) {
          $totalHitCount = $suggestions[0]['hits'];
        }
        $templateProperties = array_merge(array(
            'sSearchRequest' => array('sSearch' => $term),
            'sSearchResults' => array(
                'sResults' => $sResults,
                'sArticlesCount' => $totalHitCount,
                'sSuggestions' => $suggestions,
            ),
            'bxHasOtherItemTypes' => !empty($responses)
        ), $this->extractAutocompleteTemplateProperties($responses, $hitCount));
        if ($this->Config()->get('boxalino_categoryautocomplete_enabled')) {
            foreach ($response->propertyResults as $propertyResult) {
              if ($propertyResult->name == 'categories') {
                $propertyHits = array_map(function($hit) {
                  $categoryId = preg_replace('/\/.*/', '', $hit->label);
                  $categoryPath = Shopware()->Modules()->Categories()->sGetCategoryPath($categoryId);
                  return array(
                    'value' => $hit->value,
                    'label' => $hit->label,
                    'total' => $hit->totalHitCount,
                    'link' => $categoryPath
                  );
                }, $propertyResult->hits);
                $templateProperties = array_merge(array(
                  'bxCategorySuggestions' => $propertyHits,
                  'bxCategorySuggestionTotal' => count($propertyHits)
                ), $templateProperties);
                break;
              }
            }
        }
        $this->View()->assign($templateProperties);
        return false;
    }
    
    private function createAutocompleteRequests($term, $pageOffset, $hitCount) {
        $requests = array();
        if ($this->Config()->get('boxalino_blogsearch_enabled')) {
            $blogOptions = array(
                'returnFields' => array('products_blog_id', 'products_blog_title') 
            );
            $requests[] = $this->Helper()->newAutocompleteRequest($term, 1, $hitCount, $blogOptions, 'blog');
        }
        return $requests;
    }

    /**
     * extract preview search result
     * @param array $response
     * @return array
     */
    public function getAjaxResult($response) {
        return $this->get('legacy_struct_converter')->convertListProductStructList(
            $this->get('shopware_storefront.list_product_service')->getList(
                $this->Helper()->getAutocompletePreviewsearch($response),
                $this->get('shopware_storefront.context_service')->getProductContext()
            )
        );
    }

    /**
     * perform search
     * @param Enlight_Event_EventArgs $arguments
     * @return boolean
     */
    public function search(Enlight_Event_EventArgs $arguments) {
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

        /* @var ProductContextInterface $context */
        $context  = $this->get('shopware_storefront.context_service')->getProductContext();
        /* @var Shopware\Bundle\SearchBundle\Criteria $criteria */
        $criteria = $this->get('shopware_search.store_front_criteria_factory')
            ->createSearchCriteria($this->Request(), $context);
        
        // discard search / term conditions from criteria, such that _all_ facets are properly requested
        $criteria->removeCondition("term");
        $criteria->removeBaseCondition("search");
        $facets = $this->createFacets($criteria, $context);
        $facetIdsToOptionIds = $this->getPropertyFacetOptionIds($facets);
        
        $options = $this->getOptionsFromFacets($facets, $facetIdsToOptionIds);
        $options['sort'] = $this->getSortOrder($criteria);

        $config = $this->get('config');
        $pageCounts = array_values(explode('|', $config->get('fuzzySearchSelectPerPage')));
        $pageCount = $criteria->getLimit();
        $pageOffset = $criteria->getOffset();

        $inquiries = Array();
        $inquiries[] = $this->Helper()->newChoiceInquiry($term, $pageOffset, $pageCount, $options);
        $inquiries = array_merge($inquiries, $this->createSearchInquiries($term, $pageOffset, $pageCount));
        $response = $this->Helper()->searchAll($inquiries);
        $itemVariants = $response->variants;
        $variant = array_shift($itemVariants);
        $results = $this->Helper()->extractResults($variant);

        // decide if original result is shown or one of the relaxations
        // (the one with the largest hit count)
        $didYouMean = array();
        $facetResponses = null;
        if (
            ($amount = $config->get('boxalino_search_subphrase_amount')) > 0 &&
            $config->get('boxalino_search_subphrase_minimum') >= $results['count']
        ) {
            $count = $maxCount = 0;
            $subphrases = $this->Helper()->getRelaxationSubphraseResults($variant);
            foreach ($subphrases as $subphrase) {
                if (++$count > $amount) break;
                
                if ($subphrase['count'] >= $results['count'] && $subphrase['count'] >= $maxCount) {
                    $results = $subphrase;
                    $maxCount = $results['count'];
                    $facetResponses = $subphrase['facetResponses'];
                }
                unset($subphrase['results']);
                $didYouMean[] = $subphrase;
            }
        }
        if (
            ($amount = $config->get('boxalino_search_suggestions_amount')) > 0 &&
            ($config->get('boxalino_search_suggestions_minimum') >= $results['count'] ||
            $config->get('boxalino_search_suggestions_maximum') <= $results['count'])
        ) {
            $count = $maxCount = 0;
            $suggestions = $this->Helper()->getRelaxationSuggestions($variant);
            foreach ($suggestions as $suggestion) {
                if (++$count > $amount) break;
                
                if ($suggestion['count'] >= $results['count'] && $suggestion['count'] >= $maxCount) {
                    $results = $suggestion;
                    $maxCount = $results['count'];
                    $facetResponses = $suggestion['facetResponses'];
                }
                unset($suggestion['results']);
                $didYouMean[] = $suggestion;
            }
        }
        $facets = $this->updateFacetsWithResult($facets, $variant, $facetResponses, $facetIdsToOptionIds);
        // Get additional information for each search result
        $articles = $this->Helper()->getLocalArticles($results);

        $request = $this->Request();
        $params = $request->getParams();
        $params['sSearchOrginal'] = $term;

        // Assign result to template
        $this->View()->loadTemplate('frontend/search/fuzzy.tpl');
        $this->View()->addTemplateDir($this->Bootstrap()->Path() . 'Views/');
        $this->View()->extendsTemplate('frontend/relaxation.tpl');
        $templateProperties = array_merge(array(
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
                'sArticlesCount' => $results['count'],
                'sSuggestions' => $didYouMean,
            ),
            'productBoxLayout' => $config->get('searchProductBoxLayout'),
            'bxHasOtherItemTypes' => !empty($itemVariants),
            'bxActiveTab' => $request->getParam('bxActiveTab', 'article')
        ), $this->extractSearchTemplateProperties($itemVariants, $pageCount));
        $this->View()->assign($templateProperties);
        return false;
    }
    
    private function createSearchInquiries($term, $pageOffset, $hitCount) {
        $inquiries = array();
        if ($this->Config()->get('boxalino_blogsearch_enabled')) {
            $blogOptions = array(
                'returnFields' => array(
                    'products_blog_id'
                )
            );
            $blogPage = $this->Request()->getParam('sBlogPage', 1);
            $blogOffset = ($blogPage - 1) * $hitCount;
            $inquiries[] = $this->Helper()->newChoiceInquiry($term, $blogOffset, $hitCount, $blogOptions, 'blog');
        }
        return $inquiries;
    }
    
    private function extractSearchTemplateProperties($variants, $hitCount) {
        $props = array();
        if ($this->Config()->get('boxalino_blogsearch_enabled')) {
            $variant = array_shift($variants);
            $props = array_merge($props, $this->extractBlogSearchProperties($variant, $hitCount));
        }
        return $props;
    }
    
    private function extractBlogSearchProperties($variant, $hitCount) {
        $props = array();
        $ids = array_map(function($blog) {
            return preg_replace('/^blog_/', '', $blog->values['id'][0]);
        }, $variant->searchResult->hits);
        $total = $variant->searchResult->totalHitCount;
        $sPage = $this->Request()->getParam('sBlogPage', 1);
        $numberPages = ceil($hitCount > 0 ? $total / $hitCount : 0);
        $props['bxBlogCount'] = $total;
        $props['sNumberPages'] = $numberPages;
        if (empty($ids)) return $props;
        
        $categoryRepository = Shopware()->Models()->getRepository('Shopware\Models\Category\Category');
        $repository = Shopware()->Models()->getRepository('Shopware\Models\Blog\Blog');
        $builder = $repository->getListQueryBuilder(array());
        $query = $builder
            ->andWhere($builder->expr()->in('blog.id', $ids))
            ->getQuery()
        ;
        $pages = array();
        
        if ($numberPages > 1) {
            $params = array_merge($this->Request()->getParams(), array('bxActiveTab' => 'blog'));
            for ($i = 1; $i <= $numberPages; $i++) {
                $pages["numbers"][$i]["markup"] = $i == $sPage;
                $pages["numbers"][$i]["value"] = $i;
                $pages["numbers"][$i]["link"] = $this->assemble(array_merge($params, array('sBlogPage' => $i)));
            }
            if ($sPage > 1) {
                $pages["previous"] = $this->assemble(array_merge($params, array('sBlogPage' => $sPage - 1)));
            } else {
                $pages["previous"] = null;
            }
            if ($sPage < $numberPages) {
                $pages["next"] = $this->assemble(array_merge($params, array('sBlogPage' => $sPage + 1)));
            } else {
                $pages["next"] = null;
            }
        }
        $props['sBlogPage'] = $sPage;
        $props['sPages'] = $pages;
        $blogArticles = $this->enhanceBlogArticles($query->getArrayResult());
        $props['sBlogArticles'] = $blogArticles;
        return $props;
    }
    
    private function assemble($params) {
        $p = $this->Request()->getBasePath() . $this->Request()->getPathInfo();
        if (empty($params)) return $p;

        $ignore = array("module" => 1, "controller" => 1, "action" => 1);
        $kv = [];
        array_walk($params, function($v, $k) use (&$kv, &$ignore) {
            if ($ignore[$k]) return;
            
            $kv[] = $k . '=' . $v;
        });
        return $p . "?" . implode('&', $kv);
    }
    
    private function extractAutocompleteTemplateProperties($responses, $hitCount) {
        $props = array();
        if ($this->Config()->get('boxalino_blogsearch_enabled')) {
            $response = array_shift($responses);
            $props = array_merge($props, $this->extractBlogAutocompleteProperties($response, $hitCount));
        }
        return $props;
    }

    private function extractBlogAutocompleteProperties($response, $hitCount) {
        $searchResult = $response->hits[0]->searchResult;
        if (!$searchResult) {
            $searchResult = $response->prefixSearchResult;
        }
        if (!$searchResult) return array();
        
        $router = $this->Controller()->Front()->Router();
        $blogs = array_map(function($blog) use ($router) {
            $id = preg_replace('/^blog_/', '', $blog->values['id'][0]);
            return array(
                'id' => $id,
                'title' => $blog->values['products_blog_title'][0],
                'link' => $router->assemble(array(
                    'sViewport' => 'blog', 'action' => 'detail', 'blogArticle' => $id
                ))
            );
        }, $searchResult->hits);
        $total = $searchResult->totalHitCount;
        return array(
            'bxBlogSuggestions' => $blogs,
            'bxBlogSuggestionTotal' => $total
        );
    }
    
    // mostly copied from Frontend/Blog.php#indexAction
    private function enhanceBlogArticles($blogArticles) {
        $mediaIds = array_map(function ($blogArticle) {
            if (isset($blogArticle['media']) && $blogArticle['media'][0]['mediaId']) {
                return $blogArticle['media'][0]['mediaId'];
            }
        }, $blogArticles);
        $context = $this->Bootstrap()->get('shopware_storefront.context_service')->getShopContext();
        $medias = $this->Bootstrap()->get('shopware_storefront.media_service')->getList($mediaIds, $context);
        
        foreach ($blogArticles as $key => $blogArticle) {
            //adding number of comments to the blog article
            $blogArticles[$key]["numberOfComments"] = count($blogArticle["comments"]);
    
            //adding tags and tag filter links to the blog article
//             $tagsQuery = $this->repository->getTagsByBlogId($blogArticle["id"]);
//             $tagsData = $tagsQuery->getArrayResult();
//             $blogArticles[$key]["tags"] = $this->addLinksToFilter($tagsData, "sFilterTags", "name", false);
    
            //adding average vote data to the blog article
//             $avgVoteQuery = $this->repository->getAverageVoteQuery($blogArticle["id"]);
//             $blogArticles[$key]["sVoteAverage"] = $avgVoteQuery->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_SINGLE_SCALAR);
    
            //adding thumbnails to the blog article
            if (empty($blogArticle["media"][0]['mediaId'])) {
                continue;
            }
    
            $mediaId = $blogArticle["media"][0]['mediaId'];
    
            if (!isset($medias[$mediaId])) {
                continue;
            }
    
            /**@var $media \Shopware\Bundle\StoreFrontBundle\Struct\Media*/
            $media = $medias[$mediaId];
            $media = $this->get('legacy_struct_converter')->convertMediaStruct($media);
    
            if (Shopware()->Shop()->getTemplate()->getVersion() < 3) {
                $blogArticles[$key]["preview"]["thumbNails"] = array_column($media['thumbnails'], 'source');
            } else {
                $blogArticles[$key]['media'] = $media;
            }
        }
        return $blogArticles;
    }
    
    protected function getPropertyFacetOptionIds($facets) {
        $ids = array();
        foreach ($facets as $facet) {
            if ($facet->getFacetName() == "property") {
                $ids = array_merge($ids, $this->getValueIds($facet));
            }
        }
        $query = Shopware()->Container()->get('dbal_connection')->createQueryBuilder();
        $query->select('options.id, optionID')
            ->from('s_filter_values', 'options')
            ->where('options.id IN (:ids)')
            ->setParameter(':ids', $ids, Connection::PARAM_INT_ARRAY)
        ;
        $result = $query->execute()->fetchAll();
        $facetToOption = array();
        foreach ($result as $row) {
            $facetToOption[$row['id']] = $row['optionID'];
        }
        return $facetToOption;
    }
    
    protected function getValueIds($facet) {
        if ($facet instanceof Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup) {
            $ids = array();
            foreach ($facet->getfacetResults() as $facetResult) {
                $ids = array_merge($ids, $this->getValueIds($facetResult));
            }
            return $ids;
        } else {
            return array_map(function($value) { return $value->getId(); }, $facet->getValues());
        }
    }
    
    /**
     * Get service from resource loader
     *
     * @param string $name
     * @return mixed
     */
    public function get($name) {
        return $this->container->get($name);
    }

    /**
     * @return string
     */
    protected function getSearchTerm() {
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
    protected function searchFuzzyCheck($search) {
        $minSearch = empty($this->Config()->sMINSEARCHLENGHT) ? 2 : (int) $this->Config()->sMINSEARCHLENGHT;
        $db = Shopware()->Db();
        if (!empty($search) && strlen($search) >= $minSearch) {
            $ordernumber = $db->quoteIdentifier('ordernumber');
            $sql = $db->select()
                      ->distinct()
                      ->from('s_articles_details', array('articleID'))
                      ->where("$ordernumber = ?", $search)
                      ->limit(2);
            $articles = $db->fetchCol($sql);

            if (empty($articles)) {
                $percent = $db->quote('%');
                $sql->orWhere("? LIKE CONCAT($ordernumber, $percent)", $search);
                $articles = $db->fetchCol($sql);
            }
        }
        if (!empty($articles) && count($articles) == 1) {
            $sql = $db->select()
                      ->from(array('ac' => 's_articles_categories_ro'), array('ac.articleID'))
                      ->joinInner(
                        array('c' => 's_categories'),
                        $db->quoteIdentifier('c.id') . ' = ' . $db->quoteIdentifier('ac.categoryID') . ' AND ' .
                        $db->quoteIdentifier('c.active') . ' = ' . $db->quote(1) . ' AND ' .
                        $db->quoteIdentifier('c.id') . ' = ' . $db->quote(Shopware()->Shop()->get('parentID'))
                      )
                      ->where($db->quoteIdentifier('ac.articleID') . ' = ?', $articles[0])
                      ->limit(1);
            $articles = $db->fetchCol($sql);
        }
        if (!empty($articles) && count($articles) == 1) {
            return $this->Controller()->Front()->Router()->assemble(array('sViewport' => 'detail', 'sArticle' => $articles[0]));
        }
    }

    /**
     * @return Shopware\Bundle\SearchBundle\FacetHandlerInterface[]
     */
    protected function registerFacetHandlers() {
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
     * @return Shopware\Bundle\SearchBundle\FacetHandlerInterface
     */
    protected function getFacetHandler(SearchBundle\FacetInterface $facet) {
        if ($this->facetHandlers == null) {
            $this->facetHandlers = $this->registerFacetHandlers();
        }
        foreach ($this->facetHandlers as $handler) {
            if ($handler->supportsFacet($facet)) {
                return $handler;
            }
        }

        Shopware()->PluginLogger()->debug('Boxalino Search: Facet ' . get_class($facet) . ' not supported');
        return null;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\Criteria $criteria
     * @param ShopContextInterface $context
     * @return Shopware\Bundle\SearchBundle\FacetResultInterface[]
     */
    protected function createFacets(Shopware\Bundle\SearchBundle\Criteria $criteria, ShopContextInterface $context) {
        $facets = array();

        foreach ($criteria->getFacets() as $facet) {
            $handler = $this->getFacetHandler($facet);
            if ($handler === null) continue;

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

    protected function prepareResults($p13nResults) {
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
            $result['link'] = $this->Controller()->Front()->Router()->assemble(array('controller' => 'detail', 'sArticle' => $p13nResult['products_group_id'], 'title' => $result['name']));

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
    protected function getOptionsFromFacets($facets, $facetIdsToOptionIds = array()) {
        $options = [];
        foreach ($facets as $facet) {
            if ($facet instanceof Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup) {
                /* @var Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup $facet */
                $options = array_merge($options, $this->getOptionsFromFacets($facet->getFacetResults(), $facetIdsToOptionIds));
                break;
            }
            $key = 'property_values';
            switch ($facet->getFacetName()) {
                case 'price':
                    $min = $max = null;
                    if ($facet->isActive()) {
                        $min = $facet->getActiveMin();
                        $max = $facet->getActiveMax();
                        if ($max == 0) $max = null;
                    }
                    $options['price'] = [
                        'start' => $min,
                        'end'   => $max,
                    ];
                    break;
                case 'category':
                    $id = $label = null;
                    if (isset($_REQUEST['c'])) {
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
                    if ($key != 'brand') {
                        $peek = reset($facet->getValues());
                        if ($peek && array_key_exists($peek->getId(), $facetIdsToOptionIds)) {
                            $optionId = $facetIdsToOptionIds[$peek->getId()];
                            $key = 'optionID_'. $optionId. '_id';
                        }
                    }
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
    protected function getLowestActiveTreeItem($values) {
        foreach ($values as $value) {
            $innerValues = $value->getValues();
            if (count($innerValues)) {
                $innerValue = $this->getLowestActiveTreeItem($innerValues);
                if ($innerValue instanceof Shopware\Bundle\SearchBundle\FacetResult\TreeItem) {
                    return $innerValue;
                }
            }
            if ($value->isActive()) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\FacetResultInterface[] $facets
     * @param \com\boxalino\p13n\api\thrift\Variant $variant
     * @return Shopware\Bundle\SearchBundle\FacetResultInterface[]
     */
    protected function updateFacetsWithResult($facets, $variant, $facetResponses = null, $facetIdsToOptionIds = array()) {
        foreach ($facets as $key => $facet) {
            if ($facet instanceof Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup) {
                /* @var Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup $facet */
                $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\FacetResultGroup(
                    $this->updateFacetsWithResult($facet->getFacetResults(), $variant, $facetResponses, $facetIdsToOptionIds),
                    $facet->getLabel(),
                    $facet->getFacetName(),
                    $facet->getAttributes(),
                    $facet->getTemplate()
                );
                continue;
            }
            $productPropertyName = 'property_values';
            switch ($facet->getFacetName()) {
                case 'price':
                    /* @var Shopware\Bundle\SearchBundle\FacetResult\RangeFacetResult $facet
                     * @var com\boxalino\p13n\api\thrift\FacetValue $FacetValue */
                    $facetValue = current($this->Helper()->extractFacet($variant, 'discountedPrice', $facetResponses));
                    $from = (float) $facetValue->rangeFromInclusive;
                    $to = (float) $facetValue->rangeToExclusive;
                    $activeMin = $facet->getActiveMin();
                    if (isset($activeMin)) {
                        $activeMin = max($from, $activeMin);
                    }
                    $activeMax = $facet->getActiveMax();
                    if (isset($activeMax)) {
                        $activeMax = $activeMax == 0 ? $to : min($to, $activeMax);
                    }
                    $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\RangeFacetResult(
                        $facet->getFacetName(),
                        $facet->isActive(),
                        $facet->getLabel(),
                        $from,
                        $to,
                        $activeMin,
                        $activeMax,
                        $facet->getMinFieldName(),
                        $facet->getMaxFieldName(),
                        $facet->getAttributes(),
                        $facet->getTemplate()
                    );
                    break;
                case 'category':
                    /* @var Shopware\Bundle\SearchBundle\FacetResult\TreeFacetResult $facet */
                    $unorderedFacetValues = $this->Helper()->extractFacet($variant, 'categories', $facetResponses);
                    $FacetValues = [];
                    foreach ($unorderedFacetValues as $facetValue) {
                        if ($facetValue->hitCount > 0) {
                            $FacetValues[$facetValue->hierarchyId] = $facetValue;
                        }
                    }
                    
                    $updatedFacetValues = $this->updateTreeItemsWithFacetValue($facet->getValues(), $FacetValues);
                    if ($updatedFacetValues) {
                        $facets[$key] = new Shopware\Bundle\SearchBundle\FacetResult\TreeFacetResult(
                            $facet->getFacetName(),
                            $facet->getFieldName(),
                            $facet->isActive(),
                            $facet->getLabel(),
                            $updatedFacetValues,
                            $facet->getAttributes(),
                            $facet->getTemplate()
                        );
                    } else {
                        unset($facets[$key]);
                    }
                    break;
                case 'manufacturer':
                    $productPropertyName = 'brand';
                case 'property':
                    $facetInfo = array();
                    if ($facet->getFacetName() == 'property') {
                        $facetInfo = $this->Helper()->extractFacetByPattern($variant, '/products_optionID_[0-9]+_id/', $facetResponses);
                    } else {
                        $facetInfo = $this->Helper()->extractFacetByPattern($variant, '/products_' . $productPropertyName . '/', $facetResponses);
                    }
                    $valueList = [];
                    $nbValues = 0;
                    foreach ($facetInfo as $facetKey => $facetValues) {
                        foreach ($facetValues as $facetValue) {
                            foreach ($facet->getValues() as $valueKey => $originalValue) {
                                if ($productPropertyName == 'brand') {
                                    $check = (trim($originalValue->getLabel()) == trim($facetValue->stringValue));
                                } else {
                                    $check = ($originalValue->getId() == $facetValue->stringValue);
                                }
                                $hitCount = 0;
                                $active = $originalValue->isActive();
                                if ($check) {
                                    $hitCount = $facetValue->hitCount;
                                    $active = $facetValue->selected;
                                }
                                if ($hitCount > 0) {
                                    $nbValues++;
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
                        }
                    }
                    
                    if ($nbValues > 0) {
                        usort($valueList, function($a, $b) {
                            $res = $b->isActive() - $a->isActive();
                            if ($res !== 0) return $res;
                            
                            return strcmp($a->getLabel(), $b->getLabel());
                        });
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
                    } else {
                        unset($facets[$key]);
                    }
                    break;
                default:
                    $this->Helper()->debug("unrecognized facet name for facet", $facet);
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
        $finalVals = array();
        foreach ($values as $key => $value) {
            $id = (string) $value->getId();
            $label = $value->getLabel();
            $innerValues = $value->getValues();

            if (count($innerValues)) {
                $innerValues = $this->updateTreeItemsWithFacetValue($innerValues, $FacetValues);
            }

            if (array_key_exists($id, $FacetValues)) {
                $label .= ' (' . $FacetValues[$id]->hitCount . ')';
            } else {
                if (sizeof($innerValues)==0) {
                    continue;
                }
            }

            $finalVals[$key] = new Shopware\Bundle\SearchBundle\FacetResult\TreeItem(
                $value->getId(),
                $label,
                $value->isActive(),
                $innerValues,
                $value->getAttributes()
            );
        }
        return $finalVals;
    }

    /**
     * @param Shopware\Bundle\SearchBundle\Criteria $criteria
     * @return array
     */
    public function getSortOrder(Shopware\Bundle\SearchBundle\Criteria $criteria) {
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
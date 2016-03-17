<?php

require_once __DIR__ . '/lib/vendor/Thrift/ClassLoader/ThriftClassLoader.php';
require_once __DIR__ . '/lib/vendor/Thrift/HttpP13n.php';

class Shopware_Plugins_Frontend_Boxalino_P13NHelper {

    private static $instance = null;

    /**
     * @var Enlight_Controller_Request_Request
     */
    private $request;

    private $config;

    private $relaxationEnabled = false;

    private function __construct() {
        $this->config = Shopware()->Config();
    }

    /**
     * @return null|Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    public static function instance() {
        if (self::$instance == null)
            self::$instance = new Shopware_Plugins_Frontend_Boxalino_P13NHelper();
        return self::$instance;
    }

    public function search($text, $p13nOffset, $p13nHitCount, $options = array()) {
        $inquiry = $this->newChoiceInquiry($text, $p13nOffset, $p13nHitCount, $options);
        return $this->searchAll(array($inquiry));
    }
    
    public function searchAll($inquiries) {
        $timing = $this->newTiming("searchAll");
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');
        
        // Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);
        
        // Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);
        
        $choiceRequest->inquiries = $inquiries;
		
		// Call the service
        try {
            $choiceResponse = $p13n->choose($choiceRequest);
			if ($this->isDebug()) {
				$this->debug($choiceRequest);
				$this->debug($choiceResponse);
				exit;
			}
        
        } catch (Exception $e) {
            $this->debug("choose failed", $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Search: Error occurred with message ' . $e->getMessage());
            return;
        }
        $timing();
        return $choiceResponse;
    }
    
    public function newChoiceInquiry($text, $p13nOffset, $p13nHitCount, $options = array(), $type = 'product') {
        // ensure init thrift classloader
        new HttpP13n();
        
        // Setup main choice inquiry object
        $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
        $inquiry->choiceId = $this->config->get('boxalino_search_widget_name');

        // enable relaxation
        if (
            ($this->config->get('boxalino_search_suggestions_amount') > 0 &&
            ($this->config->get('boxalino_search_suggestions_minimum') > 0 ||
            $this->config->get('boxalino_search_suggestions_maximum') > 0)) ||
            ($this->config->get('boxalino_search_subphrase_amount') > 0 &&
            $this->config->get('boxalino_search_subphrase_minimum') > 0)
        ) {
            $inquiry->withRelaxation = $this->relaxationEnabled = true;
        }

        $searchQuery = $this->newSearchQuery($text, $p13nOffset, $p13nHitCount, $options, $type);

        // Connect search query to the inquiry
        $inquiry->simpleSearchQuery = $searchQuery;
        return $inquiry;
    }
    
    public function newSearchQuery($text, $offset, $hitCount, $options = array(), $type = 'product') {
        if ($options == null) {
            $options = array();
        }
        $options = $this->withTypeFilter($type, $options);
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $this->getAccount();
        $searchQuery->language = $this->getShortLocale();
        $searchQuery->offset = $offset;
        $searchQuery->hitCount = $hitCount;
        $searchQuery->queryText = $text;
        if ($type == 'product') {
            $searchQuery->groupBy = 'products_group_id';
        }
        $sortOrder = com\boxalino\p13n\api\thrift\FacetSortOrder::COLLATION;
        $searchQuery->facetRequests = [];
        foreach ($options as $field => $values) {
            switch ($field) {
                case 'categoryName':
                case 'sort':
                case 'filters':
                case 'returnFields':
                    continue 2;
                case 'category':
                    $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                        'fieldName' => 'category_id',
                        'selectedValues' => [new \com\boxalino\p13n\api\thrift\FacetValue([
                            'stringValue' => (string) $values
                        ])],
                        'sortOrder' => $sortOrder
                    ]);
                    $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                        'fieldName' => 'categories'
                    ]);
                    break;
                case 'price':
                    $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                        'fieldName' => 'discountedPrice',
                        'numerical' => true,
                        'range' => true,
                        'selectedValues' => [new \com\boxalino\p13n\api\thrift\FacetValue([
                            'rangeFromInclusive' => $values['start'],
                            'rangeToExclusive' => $values['end']
                        ])],
                        'boundsOnly' => true
                    ]);
                    break;
                default:
                    $selectedValues = [];
                    foreach ($values as $value) {
                        $selectedValues[] = new \com\boxalino\p13n\api\thrift\FacetValue([
                            'stringValue' => $value
                        ]);
                    }
                    $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                        'fieldName' => "products_$field",
                        'selectedValues' => $selectedValues,
                        'andSelectedValues' => ($field == 'property_values'),
                        'sortOrder' => $sortOrder
                    ]);
            }
        }

        $sortFields = [];
        if (!empty($options['sort'])) {
            $sortFields[] = new \com\boxalino\p13n\api\thrift\SortField(array(
                'fieldName' => $options['sort']['field'],
                'reverse' => $options['sort']['reverse']
            ));
        }
        if (!empty($sortFields)) {
            $searchQuery->sortFields = $sortFields;
        }
        if (!empty($options['filters'])) {
            $searchQuery->filters = array_map(function($filter) {
                return new \com\boxalino\p13n\api\thrift\Filter($filter);
            }, $options['filters']);
        }
        if (!empty($options['returnFields'])) {
            $searchQuery->returnFields = $options['returnFields'];
        } else {
            $searchQuery->returnFields = array(
                'id',
                'title',
                'body',
                'name',
                'products_group_id',
                'net_price',
                'standardPrice',
                'products_mediaId',
                'products_supplier',
                'products_net_price',
                'products_tax',
                'products_group_id'
            );
        }
        return $searchQuery;
    }

    
    public function withTypeFilter($type, $options = array()) {
        $filter = array(
            'fieldName' => 'bx_type',
            'stringValues' => array($type)
        );
        if (array_key_exists('filters', $options)) {
            $options['filters'] = array_merge(array(filter), $options['filters']);
        } else {
            $options['filters'] = array($filter);
        }
        return $options;
    }

    public function autocomplete($text, $hitCount) {
        $request = $this->newAutocompleteRequest($text, $hitCount, $hitCount);
        return $this->autocompleteAll(array($request))[0];
    }
    
    public function autocompleteAll($requests) {
        $timing = $this->newTiming("autocompleteAll");
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');
        
        $p13nSearch = $text;
        $p13nLanguage = $this->getShortLocale();
        
        // Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);
        
        // update cookies
        $p13n->getChoiceRequest($p13nAccount, $cookieDomain);
        
        // Call the service
        try {
            $requestBundle = new \com\boxalino\p13n\api\thrift\AutocompleteRequestBundle();
            $requestBundle->requests = $requests;
            $responseBundle = $p13n->autocompleteAll($requestBundle);
        } catch (Exception $e) {
            $this->debug("autocompleteAll failed", $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Autocompletion: Error occurred with message ' . $e->getMessage());
            return;
        }
        $timing();
        return $responseBundle->responses;
    }
    
    public function newAutocompleteRequest($text, $suggestionsHitCount, $hitCount, $options = array(), $type = 'product') {
        if ($hitCount == null) {
            $hitCount = $suggestionsHitCount;
        }
        if (empty($options['returnFields'])) {
            $options['returnFields'] = array(
                    'products_ordernumber',
                    'products_group_id'
            );
        }
        
        // ensure init thrift classloader
        new HttpP13n();
        $account = $this->getAccount();
        $userRecord = new \com\boxalino\p13n\api\thrift\UserRecord();
        $userRecord->username = $account;
        $request = new \com\boxalino\p13n\api\thrift\AutocompleteRequest();
        
        $searchQuery = $this->newSearchQuery($text, $offset, $hitCount, $options, $type);
        
        $autocompleteQuery = new \com\boxalino\p13n\api\thrift\AutocompleteQuery();
        $autocompleteQuery->indexId = $account;
        $autocompleteQuery->language = $this->getShortLocale();
        $autocompleteQuery->queryText = $text;
        $autocompleteQuery->suggestionsHitCount = $suggestionsHitCount;
        $autocompleteQuery->highlight = true;
        $autocompleteQuery->highlightPre = '<em>';
        $autocompleteQuery->highlightPost = '</em>';
        
        $cemv = Shopware()->Front()->Request()->getCookie('cemv');
        if (empty($cemv)) {
            $cemv = self::getSessionId();
        }
        $request->userRecord = $userRecord;
        $request->choiceId = $this->config->get('boxalino_autocomplete_widget_name');
        $request->profileId = $cemv;
        $request->autocompleteQuery = $autocompleteQuery;
        $request->searchChoiceId = $this->config->get('boxalino_search_widget_name');
        $request->searchQuery = $searchQuery;
        return $request;
    }

    public function findRawRecommendations($id, $role, $p13nChoiceId, $count = 5, $offset = 0, $fieldName = 'products_group_id', $context = array(), $contextItems = array()) {
        $timing = $this->newTiming("findRawRecommendations");
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');

        $p13nLanguage = $this->getShortLocale();
        $p13nFields = array('id', 'products_group_id');

        // Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

        // Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);
        $choiceRequest->inquiries = array();

        // Add context parameters if given
        if (count($context)) {
            foreach($context as $key => $value) {
                if (!is_array($value)) {
                    $context[$key] = array($value);
                }
            }
            $requestContext = new \com\boxalino\p13n\api\thrift\RequestContext();
            $requestContext->parameters = $context;
            $choiceRequest->requestContext = $requestContext;
        }
        
        $contextItems = array();
        if (is_array($id)) {
            $contextItems = &$id;
            $id = array_shift($id);
        }
        
        // Setup a context item
        if (!empty($id)) {
            $contextItems = array(
                new \com\boxalino\p13n\api\thrift\ContextItem(array(
                    'indexId' => $p13nAccount,
                    'fieldName' => $fieldName,
                    'contextItemId' => $id,
                    'role' => $role
                ))
            );
        }
        foreach ($contextItems as $contextItem) {
            $contextItems[] = new \com\boxalino\p13n\api\thrift\ContextItem(array(
                'indexId' => $p13nAccount,
                'fieldName' => $fieldName,
                'contextItemId' => $id,
                'role' => 'subProduct'
            ));
        }

        // Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $offset;
        $searchQuery->hitCount = $count;
        $searchQuery->groupBy = 'products_group_id';
        $searchQuery->filters = array(
            new \com\boxalino\p13n\api\thrift\Filter(array(
                'fieldName' => 'bx_type',
                'stringValues' => array('product')
            ))
        );

        if (!is_array($p13nChoiceId)) {
            $p13nChoiceId = array($p13nChoiceId);
        }
        foreach ($p13nChoiceId as $choiceId) {
            // Setup main choice inquiry object
            $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
            $inquiry->choiceId = $choiceId;
            $inquiry->minHitCount = $count;

            // Connect search query to the inquiry
            $inquiry->simpleSearchQuery = $searchQuery;
            if (!empty($id))$inquiry->contextItems = $contextItems;

            // Add inquiry to choice request
            $choiceRequest->inquiries[] = $inquiry;
        }

        // Call the service
        try {
            $choiceResponse = $p13n->choose($choiceRequest);
			if ($this->isDebug()) {
				$this->debug($choiceRequest);
				$this->debug($choiceResponse);
				exit;
			}
        } catch (Exception $e) {
            $this->debug("choose failed", $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Recommendation: Error occurred with message ' . $e->getMessage());
            return;
        }
        $timing();
        return $choiceResponse;
    }

    public function findRecommendations($id, $role, $p13nChoiceId, $count = 5, $offset = 0, $context = array(), $fieldName = 'products_group_id') {
        $results = $this->extractResults($this->findRawRecommendations($id, $role, $p13nChoiceId, $count, $offset, $fieldName, $context), $p13nChoiceId);
        if (is_array($p13nChoiceId)) {
            $articleResults = array();
            foreach ($results as $key => $result) {
                $articleResults[$key] = $this->getLocalArticles($result);
            }
            return $articleResults;
        } else {
            return $this->getLocalArticles($results);
        }
    }

    public function extractResults($variantLike, $choiceIds = array()) {
        $results = array();
        $count = 0;
        $choiceIdCount = is_array($choiceIds) ? count($choiceIds) : 0;
        /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
        foreach ($this->variantsOf($variantLike) as $variant) {
            /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
            $searchResult = $variant->searchResult;
            if ($choiceIdCount) {
                list($configOption, $choiceId) = each($choiceIds);
                $results[$configOption] = array(
                    'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                    'count' => $searchResult->totalHitCount
                );
            } else {
                $count += $searchResult->totalHitCount;
                $this->extractResultsFromHitGroups($searchResult->hitsGroups, $results);
            }
        }
        if ($choiceIdCount) {
            return $results;
        } else {
            return array('results' => $results, 'count' => $count);
        }
    }

    public function extractResultsFromHitGroups($hitsGroups, &$results = array()) {
        /** @var \com\boxalino\p13n\api\thrift\HitsGroup $group */
        foreach ($hitsGroups as $group) {
            /** @var \com\boxalino\p13n\api\thrift\Hit $item */
            foreach ($group->hits as $item) {
                $result = array();
                foreach ($item->values as $key => $value) {
                    if (is_array($value) && count($value) == 1) {
                        $result[$key] = array_shift($value);
                    } else {
                        $result[$key] = $value;
                    }
                }
                // Widget's meta data, mostly used for event tracking
                $result['_widgetTitle'] = $variant->searchResultTitle;
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * @param $variantLike
     * @param $facet
     * @return com\boxalino\p13n\api\thrift\FacetValue[]
     */
    public function extractFacet($variantLike, $facet, $facetResponses = null) {
        return $this->extractFacetByPattern($variantLike, '/' . $facet . '/', $facetResponses, true);
    }
    
    public function extractFacetByPattern($variantLike, $facetPattern, $facetResponses = null, $flat = false) {
        $facets = array();
        foreach ($this->variantsOf($variantLike) as $variant) {
            foreach ($variant->searchResult->facetResponses as $facetResponse) {
                $facets = $this->extractFacetResponse($facetPattern, $facetResponse, $facets, $flat);
            }
        }
        if ($facetResponses) {
            foreach ($facetResponses as $facetResponse) {
                $facets = $this->extractFacetResponse($facetPattern, $facetResponse, $facets, $flat);
            }
        }
        return $facets;
    }
    
    private function variantsOf($variantLike) {
        if ($variantLike instanceof \com\boxalino\p13n\api\thrift\Variant) {
            return array($variantLike);
        }
        return $variantLike->variants;
    }
    
    private function extractFacetResponse($facetPattern, $facetResponse, $facets, $flat) {
        $fieldName = $facetResponse->fieldName;
        if (!preg_match($facetPattern, $fieldName)) return $facets;
        
        $present = array();
        if ($flat) {
            $present = &$facets;
        } else if (array_key_exists($fieldName, $facets)) {
            $present = $facets[$fieldName];
        }
        $merged = array_merge($present, $facetResponse->values);
        if ($flat) return $merged;
        
        $facets[$fieldName] = $merged;
        return $facets;
    }

    public function getLocalArticles($results) {
        if (array_key_exists('results', $results)) $results = $results['results'];
        $articles = array();
        foreach ($results as $p13nResult) {
            $id = intval($p13nResult['products_group_id']);
            $articleNew = Shopware()->Modules()->Articles()->sGetPromotionById('fix', 0, $id);
            if (!empty($articleNew['articleID'])) {
                $articles[] = $articleNew;
            }
        }
        return $articles;
    }

    public function getRelaxationSuggestions(\com\boxalino\p13n\api\thrift\Variant $variant) {
        if (!$this->relaxationEnabled || !is_object($variant->searchRelaxation)) return array();
        
        $suggestions = array();
        /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
        foreach ($variant->searchRelaxation->suggestionsResults as $searchResult) {
            $suggestions[] = array(
                'text' => $searchResult->queryText,
                'count' => $searchResult->totalHitCount,
                'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                'facetResponses' => $searchResult->facetResponses
            );
        }
        return $suggestions;
    }

    public function getRelaxationSubphraseResults(\com\boxalino\p13n\api\thrift\Variant $variant) {
        if (!$this->relaxationEnabled || !is_object($variant->searchRelaxation)) return array();
        
        $subphrases = array();
        /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
        foreach ($variant->searchRelaxation->subphrasesResults as $searchResult) {
            $subphrases[] = array(
                'text' => $searchResult->queryText,
                'count' => $searchResult->totalHitCount,
                'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                'facetResponses' => $searchResult->facetResponses
            );
        }
        return $subphrases;
    }

    public function getAutocompleteSuggestions($responses, $hitCount, $merge = 1) {
        $suggestions = array();
        $otherSuggestions = array();
        $seen = array();
        $response = array_shift($responses);
        foreach ($response->hits as $hit) {
            $suggestions[] = array(
                'text' => $hit->suggestion,
                'html' => (strlen($hit->highlighted) ? $hit->highlighted : $hit->suggestion),
                'hits' => $hit->searchResult->totalHitCount,
            );
            $seen[$hit->suggestion] = true;
        }
        foreach ($responses as $response) {
            foreach ($response->hits as $hit) {
                if ($seen[$hit->suggestion]) continue;
                
                if ($merge-- <= 0) break 2;
                
                $otherSuggestions[] = array(
                    'text' => $hit->suggestion,
                    'html' => (strlen($hit->highlighted) ? $hit->highlighted : $hit->suggestion),
                    'hits' => $hit->searchResult->totalHitCount,
                );
                $seen[$hit->suggestion] = true;
            }
        }
        array_splice($suggestions, $hitCount - count($otherSuggestions));
        $merged = array_merge($suggestions, $otherSuggestions);
        return $merged;
    }
    
    public function getAutocompletePreviewsearch(\com\boxalino\p13n\api\thrift\AutocompleteResponse $response) {
        $hitsGroups = $response->prefixSearchResult->hitsGroups;
        if (!$hitsGroups) {
            foreach ($response->hits as $hit) {
                $hitsGroups = $hit->searchResult->hitsGroups;
                if ($hitsGroups) {
                    break;
                }
            }
        }
        $results = array();
        foreach ($this->extractResultsFromHitGroups($hitsGroups) as $result) {
            $results[] = $result['products_ordernumber'];
        }
        return $results;
    }

    /**
     * Sets request instance
     *
     * @param Enlight_Controller_Request_Request $request
     */
    public function setRequest(Enlight_Controller_Request_Request $request) {
        $this->request = $request;
    }

    /**
     * Returns request instance
     *
     * @return Enlight_Controller_Request_Request
     */
    public function Request() {
        return $this->request;
    }

    /**
     * @return string
     */
    public function getShortLocale() {
        $locale = Shopware()->Shop()->getLocale();
        $shortLocale = $locale->getLocale();
        $position = strpos($shortLocale, '_');
        if ($position !== false)
            $shortLocale = substr($shortLocale, 0, $position);
        return $shortLocale;
    }

    public function getSearchLimit() {
        return $this->config->get('maxlivesearchresults', 6);
    }

    public function debug($a, $b = null) {
        if ($this->isDebug()) {
            echo '<pre>';
            var_dump($a, $b);
            echo '</pre>';
        }
    }

    private function isDebug() {
        return $this->Request()->getQuery('dev_bx_disp', false) == 'true';
    }

    public static function getAccount() {
        $config = Shopware()->Config();
        if ($config->get('boxalino_dev', 0) == 1) {
            return $config->get('boxalino_account') . '_dev';
        } else{
            return $config->get('boxalino_account');
        }
    }
    
    public function getBasket($arguments = null) {
        $basket = Shopware()->Modules()->Basket()->sGetBasket();
        if ($arguments !== null && (!$basket || !$basket['content'])) {
            $basket = $arguments->getSubject()->View()->sBasket;
        }
        return $basket;
    }
    
    public static function isValidChoiceId($choiceId) {
        return strlen($choiceId) && $choiceId != "-";
    }
    
    public function newTiming($name) {
        $then = microtime(true);
        return function() use($name, $then) {
            $took = microtime(true) - $then;
            $this->debug("timing $name -- took [ms]", ($took * 1000));
        };
    }
    
}
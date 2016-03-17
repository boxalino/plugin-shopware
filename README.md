# Boxalino Shopware plugin

## Introduction

Welcome to the Boxalino Shopware v4 & v5 plugin (the plugin works in both version 4 and version 5 environments of shopware)

The Boxalino plugin for Shopware enables you to easily and quickly benefit from all the functionalities of Boxalino Intelligence:

1. Boxalino Intelligent Search with auto-correction and sub-phrases relaxation
2. Faceted search with advanced multi-type facets (refinement criteria), including the capacity to create smart facets based on unstructured textual content with our text-mining capacities and soft-facets to boost best scoring products with our unique smart-scoring algorithms.
3. Boxalino Autocomplete with advanced textual and product suggestion while you are typing (even if you type it wrong)
4. Boxalino Recommendations for real-time personalized product suggestions
5. Boxalino Optimization platform to improve step-by-step your online sales performance thanks to our revolutionary self-learning technology based on statistical testing of marketing strategies (a/b testing, self-learning clusters, and much more)

The Boxalino plugin for Shopware pre-integrates the most important key technical components of Boxalino (so you don't have to):

1. Data export (including products, customers and transaction exports for multi-shops with test and live accounts and supporting regular delta synchronizations)
2. Boxalino tracker (pre-integration of Boxalino JavaScript tracker, our own tracker which follows strictly the Google Analytics model).
3. Search, Autocomplete and layered navigation (faceted navigation) with all intelligence functionalities pre-integrated (auto-correction, sub-phrases relaxation, etc.)
4. Similar and Complementary recommendations on product page and cross-selling on basket (cart) page
5. Layered navigation, to let Boxalino optimize the entire product navigation on your web-site

In addition, it is very easy to extend this pre-installed set-up to benefit from the following additional features:

1. Recommendations everywhere (easy to extend recommendations widgets on the home page, category pages, landing pages, content pages, etc.).
2. Quick-finder to enable new ways to find product with simple search criteria and compine it with soft-facets with our unique smart-scoring capacities (see an example here with the gift-finder of www.geschenkidee.ch).
3. Personalized newsletter & trigger personalized mail (use the base of data export and tracking from our plugin to simply integrate personalized product recommendations in your e-mail marketing activities and push notifications on your mobile app)
4. Advanced reporting to integrate any learnings and analysis of your online behaviors in other Business Intelligence and Data Mining projects with our flexible Reporting API functionalities

If you need more information on any of these topics, please don't hesitate to contact Boxalino at sales@boxalino.com. We will be glad to assist you!

## Installation

1. Download the archive.
2. In your administration backend *install the Boxalino plugin* (System > Plugin Manager). A configuration will appear where you can insert your private and public keys and modify other information. The plugin will not work until you save the settings. Later the configuration will be available under Configuration > Basic Settings > Additional Settings > boxalino.
3. If anything is changed in boxalino plugin or its settings then you need [clear the cache](http://community.shopware.com/Cache_detail_855.html#Start).
4. You can manually export data from *Content > Import / export > Boxalino Export*. You'll have to refresh the page to see this new menu entry. By default a daily full export is scheduled.

## Manual export

1. Navigate to *Content > Import / export > Boxalino Export*.
2. Choose either *Full export* or *Delta export*. Full export exports all data to the server. Delta export exports only data modified later then last export was made.
3. Exporting can take some time. Please *wait for a popup window* with results. *Some browsers may try to block it from appearing.*
4. After the export you may also be required to take some actions in *boxalino's Data Intelligence administration panel*.

## Documentation

http://documentation.boxalino.com/boxalino-shopware-plugin/

## Adding Recommendations

### General instructions

To add/substitute another recommendation widget:

1. Add new widget in the Shopware frontend or find which one you wish to modify. You will need name of controller and action which show this widget on the webpage as well as variable which stores list of shown items.
2. Use existing classes, i.e. *Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor* to intercept the page before it is fully rendered. There you can modify displayed data. Choosing which class to use depends on namespace of intercepted action (frontend or widget).
3. Methods Shopware_Plugins_Frontend_Boxalino_P13NHelper::findRecommendations and Shopware_Plugins_Frontend_Boxalino_GenericRecommendations::getArticlesForChoice returns an array of items found in p13n. You can bind them in the interceptor to view variables used in you recommendation widget like this:


    $view->items = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance()->findRecommendations(articleID, role, boxalino_widget_id, results_count);

or

    $recommender = new Shopware_Plugins_Frontend_Boxalino_GenericRecommendations;
    $recommendations = $recommender->getArticlesForChoice(boxalino_widget_id, results_count, optional_context_array);
    $view->items = $recommendations['results'];

In Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor you can also see event reporting. In case you want to extend reporting this is the place where you can do it.

### Case study of replacing existing element - changing _similar items_ section in item detail page

In Shopware, I have a page displaying item details. Next to item's description there's is a small box showing similar items which I may be interested in. I want those items to be suggested by Boxalino because it knows better.

#### Finding the correct spot

Firstly, I need to find out where the displayed items actually come from. This is not hard but, unfortunately, it may take some time of tedious file navigation. Let's begin.

##### Action and controller
Firstly, I need to find out which controller and action displays this page. The information can be retrieved from request parameters. The easiest way to peek at this data is to actually print it on screen. To do that you can use existing code in /engine/Shopware/Plugins/Local/Frontend/Boxalino/Bootstrap.php. There are to methods: _onFrontend_ and _onWidget_. If I uncomment the following lines in _onFrontend_:
    $controller = $arguments->getSubject();
    $request = $controller->Request();
    var_dump($request->getParams());

after refreshing, I can see on the bottom of item detail page this:

    array (size=4)
      'rewriteUrl' => string '1' (length=1)
      'sViewport' => string 'detail' (length=6)
      'sArticle' => string '19' (length=2)
      'controller' => string 'detail' (length=6)

This output tells me that the page was rendered by controller _detail_. The action is not specified so it must have been _index_. And because this came from _onFrontend_ I know, that the package I need to look into is called _Frontend_. I also note that _sArticle_ contains the id of displayed item.

This leads me to file _/engine/Shopware/Controllers/Frontend/Detail.php_ with class _Shopware_Controllers_Frontend_Detail_ and method _indexAction_. I should now comment back lines which gave me this information.

> **AJAX may complicate things**
> 
> Sometimes you may be replacing content returned by AJAX. The _var_dump_ is still useful for getting controller name, however the output may be visible only in page inspector (or Firebug). You may have to check "Network" section and look at the response. It may also include some HTML formatting making it harder to read but the date should be there.

##### Template

I now know which action displays the page: _Shopware_Controllers_Frontend_Detail::indexAction_. This means that (unless something else is explicitly stated in the code, usually _View()->loadTemplate_) the template showing this page is _/templates/_default/frontend/detail/index.tpl_. (parts of the path correspond to the controller and action). In there I finally find the following lines:

    {* Related articles *}
    {block name="frontend_detail_index_tabs_related"}
        {include file="frontend/detail/related.tpl"}
    {/block}

These are responsible for displaying the component I want to modify. I'm almost there.

##### Variables

Finally, in file _/templates/_default/frontend/detail/related.tpl_ is the line I'm looking for:

    {foreach from=$sArticle.sRelatedArticles item=sArticleSub key=key name="counter"}

This is the actual loop that shows similar items. I can see that it works on property _sRelatedArticles_ of object _sArticle_. This is the item and property I will have to modify. It's high time I commented out the code from first point.

> **Difficulties while browsing the code**
> 
> Unfortunately, you may often have to guess which variable is responsible for what. Sometimes, there's more then one object that needs to be modified. For example, both _$sCrossSimilarShown_ and _$sCrossBoughtToo_ are responsible for showing similar items when adding to cart.

#### Intercepting and feeding data

I remember I was using method _onFrontend_ to get controller and action. Now this method contains only call to _Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor::intercept_. I'm going to use this method to actually insert needed data into the view.

And just to collect all the information gathered up to this point in one place:
* Package: Frontend
* Controller: Details
* Action: empty (defaults to _index_)
* Parameter with item ID: sArticle
* View object: sArticle
* Property: sRelatedArticles

##### Intercepting correct request

In this method I have available two variables _$controllerName_ and _$actionName_. I have to check their values to intercept the wanted request. Controller was called _detail_ and action was empty (default, meaning _index_) so my code for intercepting this and only this request is as follows:

    if ($controllerName == 'detail') {
        if (empty($actionName)) {
            // Here I will replace view data
        }
    }

##### Replacing data

Firstly, I have to get the object _sArticle_ which is used in the template. I have variable _$view_ to help me with that:

    $sArticle = $view->sArticle;

Then, I need to get replacement data. New items can be requested like this:

    $term = trim(strip_tags(htmlspecialchars_decode(stripslashes($request->sArticle))));
    $similarArticles = $this->helper->findRecommendations(
        $term,
        'mainProduct',
        Shopware()->Config()->get('boxalino_search_widget_name')
    );

I need to find out what item I was looking at -- this information is stored in _$request->sArticle_. The stripped data is passed to p13n by calling _$this->helper->findRecommendations_. For arguments it takes: _id of main object_, _role_ and _p13n widget id_. This function requests information from p13n and then translates it into "native" shopware objects. Usually there's no need for further modification of the data.

##### Putting it back in the view

The final step is to put the new data back in the view. Firstly, I have to update the object _$sArticle_ and then re-assign it in the view:

    $sArticle['sSimilarArticles'] = $similarArticles;
    $view->assign('sArticle', $sArticle);

After this new data should be displayed on the page.

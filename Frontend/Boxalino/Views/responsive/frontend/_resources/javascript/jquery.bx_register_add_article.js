$.subscribe('plugin/swCollapseCart/onLoadCartFinished', function() {
	StateManager.addPlugin('*[data-add-article="true"]', 'swAddArticle');
});

/*
 * need to subscribe to onArticleAdded and onRemoveArticleFinished because onLoadCartFinished is not triggered
 * when adding or removing an article within the cart
*/
$.subscribe('plugin/swCollapseCart/onArticleAdded', function() {
	StateManager.addPlugin('*[data-add-article="true"]', 'swAddArticle');
});
$.subscribe('plugin/swCollapseCart/onRemoveArticleFinished', function() {
	StateManager.addPlugin('*[data-add-article="true"]', 'swAddArticle');
});

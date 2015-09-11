{block name='search_ajax_inner' prepend}
	<ul class="results--list">
		{foreach $sSearchResults.sSuggestions as $suggestion}
			<li class="list--entry block-group result--item">
				<a class="search-result--link" href="{url controller='search' sSearch=$suggestion.text}" title="{$suggestion.text|escape}">
					{$suggestion.html} ({$suggestion.hits})
				</a>
			</li>
		{/foreach}
	</ul>
{/block}

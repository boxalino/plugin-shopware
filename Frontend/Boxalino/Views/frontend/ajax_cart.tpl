{block name='frontend_checkout_ajax_cart_button_container' append}
	<div class="button--container">
		{foreach $sSearchResults.sSuggestions as $suggestion}
			<li class="list--entry block-group result--item">
				<a class="search-result--link" href="{url controller='search' sSearch=$suggestion.text}" title="{$suggestion.text|escape}">
					{$suggestion.html} ({$suggestion.hits})
				</a>
			</li>
		{/foreach}
	</div>
{/block}

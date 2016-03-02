var startIndex = $(".tab-menu--search .tab--navigation .tab--link").filter(".is--active").index();
StateManager.addPlugin('.tab-menu--search', 'swTabMenu', {startIndex: startIndex});
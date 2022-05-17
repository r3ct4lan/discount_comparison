<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('r3ct4lan.discount_comparison', [
	'\\R3ct4lan\\DiscountComparison\\Events\\Providers' => 'classes/Events/Providers.php', //обработка событий для классов провайдеров
	'\\R3ct4lan\\DiscountComparison\\Providers\\Actions' => 'classes/Providers/Actions.php', //провайдер, расчитывающий скидки
	'\\R3ct4lan\\DiscountComparison\\Providers\\Discount' => 'classes/Providers/Discount.php', //провайдер, применяющий ПРСК
]);

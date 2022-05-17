<?php

namespace R3ct4lan\DiscountComparison\Events;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Loader;
use Bitrix\Sale\Registry;
use R3ct4lan\DiscountComparison\Providers\Discount;

class Providers
{
	/**
	 * Устанавливается свой провайдер скидок
	 * @throws ArgumentException
	 * @throws LoaderException
	 */
	public static function onPageStart()
	{
		if (Loader::includeModule('sale')) {
			Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->set(Registry::ENTITY_DISCOUNT, Discount::class);
		}
	}
}